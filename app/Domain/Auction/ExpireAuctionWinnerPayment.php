<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\AuctionItem;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Handles a lot whose winner failed to pay within the deadline.
 *
 * Called by a scheduled command after payment_deadline has passed.
 * Idempotent: already-failed or non-sold items are a no-op.
 *
 * Flow:
 *   1. Lock the item row.
 *   2. Verify fulfillment_status = 'awaiting_payment' and deadline has passed.
 *   3. Cancel the winning bid's Stripe PaymentIntent.
 *   4. Set fulfillment_status → 'payment_failed'.
 *   5. Mark the winning bid as 'retracted'.
 */
final class ExpireAuctionWinnerPayment
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function execute(AuctionItem $item): void
    {
        DB::transaction(function () use ($item): void {
            /** @var AuctionItem $locked */
            $locked = AuctionItem::lockForUpdate()->findOrFail($item->id);

            if ($locked->fulfillment_status !== 'awaiting_payment') {
                return; // already handled — idempotent
            }

            if ($locked->payment_deadline === null || $locked->payment_deadline->isFuture()) {
                return; // deadline not set or not yet passed
            }

            $bid = $locked->winningBid;

            if ($bid?->stripe_payment_intent_id) {
                try {
                    $this->stripe->paymentIntents->cancel($bid->stripe_payment_intent_id);
                } catch (\Throwable) {
                    // Already canceled or expired — safe to ignore
                }
            }

            if ($bid) {
                $bid->status = 'retracted';
                $bid->save();
            }

            $locked->fulfillment_status = 'payment_failed';
            $locked->save();
        });
    }
}
