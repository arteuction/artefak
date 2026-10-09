<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\AuctionItem;
use App\Models\Bid;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Re-authorizes payment for a winning bid whose Stripe authorization expired.
 *
 * Called when the winner wants to complete the purchase after the 7-day window.
 * Creates a new PaymentIntent in manual-capture mode, updates the bid's
 * stripe_payment_intent_id and resets payment_status to 'authorized'.
 *
 * Guards:
 *   - Bid must belong to the caller.
 *   - Bid status must be 'won'.
 *   - payment_status must be 'authorization_expired' (not 'captured').
 *   - AuctionItem must still be 'sold' (not disputed/reversed).
 */
final class ReAuthorizeBid
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function execute(
        AuctionItem $item,
        Bid         $bid,
        int         $callerId,
        string      $stripePaymentMethodId,
    ): Bid {
        if ($bid->user_id !== $callerId) {
            throw new \DomainException('Only the winning bidder may re-authorize payment.');
        }

        if ($bid->status !== 'won') {
            throw new \DomainException("Bid #{$bid->id} is not in 'won' status.");
        }

        if ($bid->payment_status === 'captured') {
            throw new \DomainException("Bid #{$bid->id} is already captured.");
        }

        if ($bid->payment_status !== 'authorization_expired') {
            throw new \DomainException("Bid #{$bid->id} payment_status is '{$bid->payment_status}'; only 'authorization_expired' can be re-authorized.");
        }

        return DB::transaction(function () use ($item, $bid, $stripePaymentMethodId): Bid {
            $auction = $item->auction;
            $authorizationExpiresAt = now()->addDays(7);

            $pi = $this->stripe->paymentIntents->create([
                'amount'         => $bid->amount_cents,
                'currency'       => strtolower($auction->currency ?? 'eur'),
                'payment_method' => $stripePaymentMethodId,
                'capture_method' => 'manual',
                'confirm'        => true,
                'metadata'       => [
                    'bid_id'          => $bid->id,
                    'auction_item_id' => $item->id,
                    'auction_id'      => $item->auction_id,
                    're_authorization' => true,
                ],
            ]);

            $bid->update([
                'stripe_payment_intent_id' => $pi->id,
                'payment_status'           => 'authorized',
                'authorization_expires_at' => $authorizationExpiresAt,
            ]);

            return $bid->refresh();
        });
    }
}
