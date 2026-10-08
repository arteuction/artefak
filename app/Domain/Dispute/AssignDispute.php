<?php

declare(strict_types=1);

namespace App\Domain\Dispute;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\Dispute;
use App\Models\User;

final class AssignDispute
{
    public function execute(Dispute $dispute, User $assignee): Dispute
    {
        if ($dispute->isResolved()) {
            throw new \DomainException("Cannot assign a resolved dispute (status: {$dispute->status}).");
        }

        $dispute->update([
            'assigned_to' => $assignee->id,
            'status'      => 'under_review',
        ]);

        (new AppendDomainEvent())->execute(
            aggregate:      $dispute,
            eventType:      'dispute.assigned',
            payload:        ['assigned_to' => $assignee->id],
            idempotencyKey: "dispute.assigned:{$dispute->id}:{$assignee->id}",
        );

        return $dispute->fresh();
    }
}
