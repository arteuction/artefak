<?php

declare(strict_types=1);

namespace App\Domain\Dispute;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\Dispute;
use App\Models\User;
use InvalidArgumentException;

final class ResolveDispute
{
    /**
     * @param  string  $outcome  'resolved' | 'dismissed'
     */
    public function execute(
        Dispute $dispute,
        User    $resolvedBy,
        string  $outcome,
        string  $resolution,
    ): Dispute {
        if (! in_array($outcome, ['resolved', 'dismissed'], true)) {
            throw new InvalidArgumentException("Outcome must be 'resolved' or 'dismissed', got '{$outcome}'.");
        }

        if ($dispute->isResolved()) {
            throw new \DomainException("Dispute is already {$dispute->status}.");
        }

        $dispute->update([
            'status'      => $outcome,
            'resolution'  => $resolution,
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy->id,
        ]);

        (new AppendDomainEvent())->execute(
            aggregate:      $dispute,
            eventType:      "dispute.{$outcome}",
            payload:        ['resolved_by' => $resolvedBy->id, 'resolution' => $resolution],
            idempotencyKey: "dispute.{$outcome}:{$dispute->id}",
        );

        return $dispute->fresh();
    }
}
