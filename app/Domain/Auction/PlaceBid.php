<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\AuctionItem;
use App\Models\Bid;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Places a bid on an open auction item.
 *
 * Steps (all inside a DB transaction with a row-level lock):
 *   1. Re-read the item with lockForUpdate — verify it is still 'open'.
 *   2. Validate amount >= nextBidCents().
 *   3. INSERT bid as 'pending'.
 *   4. Create a Stripe PaymentIntent in manual-capture mode (authorize only).
 *   5. Mark bid 'accepted', previous highest bid 'outbid'.
 *
 * Returns the newly created Bid (with stripe_payment_intent_id populated).
 */
final class PlaceBid
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function execute(
        AuctionItem $item,
        int         $bidderId,
        int         $amountCents,
        string      $stripePaymentMethodId,
        string      $ipAddress = '',
    ): Bid {
        return DB::transaction(function () use ($item, $bidderId, $amountCents, $stripePaymentMethodId, $ipAddress): Bid {
            /** @var AuctionItem $locked */
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->status !== 'open') {
                throw new BidRejected("Lot #{$locked->lot_number} is not open for bidding.");
            }

            $minimum = $locked->nextBidCents();
            if ($amountCents < $minimum) {
                throw new BidRejected(
                    "Bid of {$amountCents} cents is below the minimum of {$minimum} cents."
                );
            }

            // Persist bid as pending first (no PI yet)
            $bid = Bid::create([
                'auction_item_id' => $locked->id,
                'user_id'         => $bidderId,
                'amount_cents'    => $amountCents,
                'status'          => 'pending',
                'ip_address'      => $ipAddress ?: null,
            ]);

            // Authorize (manual capture) via Stripe — outside the lock window
            // but inside the transaction so we can roll back on Stripe failure.
            $pi = $this->stripe->paymentIntents->create([
                'amount'               => $amountCents,
                'currency'             => strtolower($locked->auction->currency ?? 'eur'),
                'payment_method'       => $stripePaymentMethodId,
                'capture_method'       => 'manual',
                'confirm'              => true,
                'metadata'             => [
                    'bid_id'          => $bid->id,
                    'auction_item_id' => $locked->id,
                    'auction_id'      => $locked->auction_id,
                ],
            ]);

            $bid->stripe_payment_intent_id = $pi->id;
            $bid->status = 'accepted';
            $bid->save();

            // Outbid any previously accepted bid on this item
            Bid::where('auction_item_id', $locked->id)
                ->where('status', 'accepted')
                ->where('id', '!=', $bid->id)
                ->update(['status' => 'outbid']);

            return $bid->refresh();
        });
    }
}
