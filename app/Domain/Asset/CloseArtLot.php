<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtLot;

/**
 * Close an ArtLot — transitions to 'sold' or 'unsold'.
 *
 * Call after a SellNowOffer is accepted, or when an auction ends with no buyer.
 * Append-only: the lot's final status is recorded via a domain event; never edit the
 * record post-close (corrections require a new event if the original trade is unwound).
 */
final class CloseArtLot
{
    public const STATUS_SOLD   = 'sold';
    public const STATUS_UNSOLD = 'unsold';

    public function execute(
        ArtLot  $artLot,
        string  $outcome,      // 'sold' | 'unsold'
        ?int    $soldPriceCents = null,
        ?int    $buyerId        = null,
        string  $idempotencyKey = '',
    ): ArtLot {
        if (! in_array($outcome, [self::STATUS_SOLD, self::STATUS_UNSOLD], true)) {
            throw new \InvalidArgumentException("Unknown outcome '{$outcome}'.");
        }

        if ($artLot->status === $outcome) {
            return $artLot;
        }

        $allowedFrom = ['active', 'scheduled', 'catalogued'];
        if (! in_array($artLot->status, $allowedFrom, true)) {
            throw new \DomainException(
                "ArtLot #{$artLot->id} cannot be closed from status '{$artLot->status}'."
            );
        }

        $artLot->update([
            'status'    => $outcome,
            'closed_at' => now(),
        ]);

        $key = $idempotencyKey ?: "art_lot.{$outcome}:{$artLot->id}";

        (new AppendDomainEvent())->execute(
            aggregate:      $artLot,
            eventType:      "art_lot.{$outcome}",
            payload:        array_filter([
                'sold_price_cents' => $soldPriceCents,
                'buyer_id'         => $buyerId,
            ]),
            idempotencyKey: $key,
        );

        return $artLot->refresh();
    }
}
