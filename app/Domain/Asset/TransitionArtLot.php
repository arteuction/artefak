<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtLot;

/**
 * Generic single-step lot state machine transition.
 *
 * Each allowed transition is declared here; callers pick by name.
 * All transitions are idempotent if the lot is already in the target status.
 */
final class TransitionArtLot
{
    // allowed[from] = [to, event_type]
    private const TRANSITIONS = [
        'submit'    => ['from' => ['draft'],        'to' => 'submitted',   'event' => 'art_lot.submitted'],
        'verify'    => ['from' => ['submitted'],    'to' => 'verification','event' => 'art_lot.verification_started'],
        'approve'   => ['from' => ['verification'], 'to' => 'approved',    'event' => 'art_lot.approved'],
        'catalogue' => ['from' => ['approved'],     'to' => 'catalogued',  'event' => 'art_lot.catalogued'],
        'schedule'  => ['from' => ['catalogued'],   'to' => 'scheduled',   'event' => 'art_lot.scheduled'],
        'activate'  => ['from' => ['scheduled', 'catalogued', 'approved'], 'to' => 'active', 'event' => 'art_lot.activated'],
    ];

    public function execute(ArtLot $artLot, string $transition, array $payload = []): ArtLot
    {
        if (! isset(self::TRANSITIONS[$transition])) {
            throw new \InvalidArgumentException("Unknown transition '{$transition}'.");
        }

        $def = self::TRANSITIONS[$transition];

        // Idempotent: already at target
        if ($artLot->status === $def['to']) {
            return $artLot;
        }

        if (! in_array($artLot->status, $def['from'], true)) {
            throw new \DomainException(
                "ArtLot #{$artLot->id} cannot be '{$transition}': current status is '{$artLot->status}'."
            );
        }

        $artLot->update(['status' => $def['to']]);

        (new AppendDomainEvent())->execute(
            aggregate:      $artLot,
            eventType:      $def['event'],
            payload:        array_merge(['lot_id' => $artLot->id], $payload),
            idempotencyKey: "{$def['event']}:{$artLot->id}:{$def['to']}",
        );

        return $artLot->fresh();
    }
}
