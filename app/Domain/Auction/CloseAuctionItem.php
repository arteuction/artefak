<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Domain\Asset\CloseArtLot;
use App\Domain\Outbox\AppendDomainEvent;
use App\Events\AuctionItemStatusChanged;
use App\Models\ArtLot;
use App\Models\AuctionItem;
use App\Models\Bid;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Closes an auction lot when bidding time is up.
 *
 * If a winning bid exists:
 *   1. Lock item row.
 *   2. Set item status → 'sold', winning_bid_id = highest accepted bid.
 *   3. Set winning bid status → 'won'.
 *   4. Cancel the Stripe PaymentIntents for all outbid bids.
 *
 * If no accepted bids exist: status → 'passed'.
 * Idempotent: already-closed items are a no-op.
 */
final class CloseAuctionItem
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function execute(AuctionItem $item): void
    {
        DB::transaction(function () use ($item): void {
            /** @var AuctionItem $locked */
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if (in_array($locked->status, ['sold', 'passed', 'canceled', 'reserve_not_met'], true)) {
                return; // already closed — idempotent
            }

            $winner = Bid::where('auction_item_id', $locked->id)
                ->where('status', 'accepted')
                ->orderByDesc('amount_cents')
                ->first();

            if ($winner === null) {
                $locked->status = 'passed';
                $locked->save();
                return;
            }

            // Reserve check: only when the auction has a ruleset with reserve enabled
            // AND the art lot has a reserve_price_cents set.
            $reservePrice   = $locked->artLot?->reserve_price_cents;
            $reserveEnabled = $locked->auction?->ruleset?->reserve_enabled ?? false;

            if ($reserveEnabled && $reservePrice !== null && $winner->amount_cents < $reservePrice) {
                // Reserve not met — enter seller-review workflow
                (new EvaluateReserve())->execute($locked, $winner->amount_cents);
                return;
            }

            $locked->status              = 'sold';
            $locked->winning_bid_id      = $winner->id;
            $locked->fulfillment_status  = 'awaiting_payment';
            $locked->payment_deadline    = now()->addHours(48);
            $locked->save();

            $winner->status = 'won';
            $winner->save();

            (new AppendDomainEvent())->execute(
                aggregate: $locked,
                eventType: 'auction_item.sold',
                payload: [
                    'auction_id'           => $locked->auction_id,
                    'winning_bid_id'       => $winner->id,
                    'winning_bid_cents'    => $winner->amount_cents,
                    'winner_id'            => $winner->user_id,
                    'art_lot_id'           => $locked->art_lot_id,
                ],
                idempotencyKey: "auction_item.sold:{$locked->id}",
            );

            // Propagate sold status to the parent ArtLot so the lot is no longer available
            if ($locked->art_lot_id !== null) {
                $artLot = ArtLot::find($locked->art_lot_id);
                if ($artLot !== null) {
                    (new CloseArtLot())->execute(
                        artLot:         $artLot,
                        outcome:        CloseArtLot::STATUS_SOLD,
                        soldPriceCents: $winner->amount_cents,
                        buyerId:        $winner->user_id,
                        idempotencyKey: "art_lot.sold.auction:{$artLot->id}:{$locked->id}",
                    );
                }
            }
        });

        // Broadcast lot closure outside the transaction
        $closedItem = AuctionItem::find($item->id);
        if ($closedItem) {
            AuctionItemStatusChanged::dispatch(
                auctionItemId:    $closedItem->id,
                auctionId:        $closedItem->auction_id,
                newStatus:        $closedItem->status,
                hammerPriceCents: $closedItem->status === 'sold'
                    ? ($closedItem->winningBid?->amount_cents)
                    : null,
                currency:         $closedItem->auction?->currency ?? 'EUR',
            );
        }

        // Cancel outbid PaymentIntents outside the transaction (Stripe call)
        $outbidPIs = Bid::where('auction_item_id', $item->id)
            ->where('status', 'outbid')
            ->whereNotNull('stripe_payment_intent_id')
            ->pluck('stripe_payment_intent_id');

        foreach ($outbidPIs as $piId) {
            try {
                $this->stripe->paymentIntents->cancel($piId);
            } catch (\Throwable) {
                // Already canceled or expired — safe to ignore
            }
        }
    }
}
