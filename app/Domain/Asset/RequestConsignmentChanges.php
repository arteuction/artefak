<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\Consignment;
use App\Models\User;

/**
 * Gallery requests changes to a consignment before approving.
 *
 * Keeps the consignment in 'draft' status but emits a domain event so the
 * artist/owner is notified.  The reason is stored in the event payload; the
 * calling layer is responsible for surfacing it to the owner (email, notification).
 */
final class RequestConsignmentChanges
{
    public function execute(
        Consignment $consignment,
        User        $requestedBy,
        string      $reason,
    ): void {
        if (! in_array($consignment->status, ['draft'], true)) {
            throw new \DomainException(
                "Cannot request changes on consignment #{$consignment->id} with status '{$consignment->status}'."
            );
        }

        (new AppendDomainEvent())->execute(
            aggregate:      $consignment,
            eventType:      'consignment.changes_requested',
            payload:        [
                'requested_by' => $requestedBy->id,
                'reason'       => $reason,
                'gallery_id'   => $consignment->gallery_id,
            ],
            idempotencyKey: "consignment.changes_requested:{$consignment->id}:{$requestedBy->id}:" . md5($reason),
        );
    }
}
