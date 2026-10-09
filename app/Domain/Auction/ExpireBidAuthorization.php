<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\Bid;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Marks a bid's PaymentIntent authorization as expired and cancels it on Stripe.
 *
 * Called for bids in status 'accepted'/'won' whose authorization_expires_at has
 * passed. For outbid bids CloseAuctionItem already cancels the PI; this handles
 * the winning-bid expiry edge case that CloseAuctionItem cannot address (it runs
 * at auction close, not during the 7-day payment window).
 *
 * After calling this action, the winning bidder must re-authorize payment via a
 * new PaymentIntent. The bid status remains 'won' so the auction result is
 * preserved, but payment_status becomes 'authorization_expired' to block capture.
 */
final class ExpireBidAuthorization
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function execute(Bid $bid): void
    {
        if ($bid->payment_status === 'captured') {
            return; // already paid — nothing to expire
        }

        if ($bid->stripe_payment_intent_id !== null) {
            try {
                $this->stripe->paymentIntents->cancel($bid->stripe_payment_intent_id);
            } catch (ApiErrorException) {
                // PI may already be canceled or expired on Stripe's side — safe to ignore
            }
        }

        $bid->update(['payment_status' => 'authorization_expired']);
    }
}
