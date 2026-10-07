<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Models\Reserve;

/**
 * Seller proposes a counter-offer price to the highest bidder.
 *
 * Transitions:
 *   Reserve: not_reached → counter_offered
 *
 * The counter-offer price must be:
 *   - Below the reserve (otherwise just waive/approve)
 *   - Above the highest bid (otherwise just waive)
 *
 * Acceptance of the counter-offer (buyer pays the counter price) is a
 * separate flow handled outside this action.
 */
final class IssueCounterOffer
{
    public function execute(
        Reserve  $reserve,
        int      $counterOfferCents,
        int      $decidedBy,
        int      $expiresInHours = 48,
        ?string  $notes = null,
    ): void {
        if ($reserve->status !== 'not_reached') {
            throw new \InvalidArgumentException(
                "Reserve #{$reserve->id} is already resolved (status: {$reserve->status})."
            );
        }

        if ($counterOfferCents <= $reserve->highest_bid_cents) {
            throw new \InvalidArgumentException(
                "Counter-offer of {$counterOfferCents} cents must exceed the highest bid of {$reserve->highest_bid_cents} cents."
            );
        }

        if ($counterOfferCents >= $reserve->reserve_price_cents) {
            throw new \InvalidArgumentException(
                "Counter-offer of {$counterOfferCents} cents equals or exceeds the reserve of {$reserve->reserve_price_cents} cents — use WaiveReserve instead."
            );
        }

        $reserve->update([
            'status'                   => 'counter_offered',
            'counter_offer_cents'      => $counterOfferCents,
            'counter_offer_expires_at' => now()->addHours($expiresInHours),
            'decided_by'               => $decidedBy,
            'decided_at'               => now(),
            'notes'                    => $notes,
        ]);
    }
}
