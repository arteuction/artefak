<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\Consignment;
use App\Models\User;

/**
 * Gallery approves a consignment — transitions draft → active.
 *
 * The approving user must hold an active 'owner' or 'curator' role at
 * the gallery the consignment is assigned to.
 */
final class ApproveConsignment
{
    public function execute(Consignment $consignment, User $approvedBy): Consignment
    {
        if ($consignment->status !== 'draft') {
            throw new \DomainException(
                "Cannot approve consignment #{$consignment->id} with status '{$consignment->status}'."
            );
        }

        $gallery = $consignment->gallery;

        if ($gallery && ! ($gallery->hasRole($approvedBy, 'owner') || $gallery->hasRole($approvedBy, 'curator'))) {
            throw new \DomainException(
                "User #{$approvedBy->id} must hold owner or curator role to approve this consignment."
            );
        }

        $consignment->update([
            'status'     => 'active',
            'starts_at'  => $consignment->starts_at ?? now(),
        ]);

        (new AppendDomainEvent())->execute(
            aggregate:      $consignment,
            eventType:      'consignment.approved',
            payload:        ['gallery_id' => $gallery?->id, 'approved_by' => $approvedBy->id],
            idempotencyKey: "consignment.approved:{$consignment->id}",
        );

        return $consignment->refresh();
    }
}
