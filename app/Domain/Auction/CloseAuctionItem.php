<?php

declare(strict_types=1);

namespace App\Domain\Auction;

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

            if (in_array($locked->status, ['sold', 'passed', 'canceled'], true)) {
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

            $locked->status         = 'sold';
            $locked->winning_bid_id = $winner->id;
            $locked->save();

            $winner->status = 'won';
            $winner->save();
        });

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
