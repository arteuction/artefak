<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Consignment;
use App\Models\User;
use App\Notifications\ConsignmentChangesRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Wired to the synthetic Laravel event ConsignmentChangesRequested,
 * dispatched by the domain event consumer when it processes
 * 'consignment.changes_requested' from the outbox.
 */
final class SendConsignmentChangesNotification implements ShouldQueue
{
    public function handle(\App\Events\ConsignmentChangesRequested $event): void
    {
        $consignment = Consignment::with(['artwork', 'gallery', 'owner'])->find($event->consignmentId);

        if ($consignment === null) {
            return;
        }

        $owner = User::find($consignment->owner_id);
        $owner?->notify(new ConsignmentChangesRequestedNotification(
            consignment:     $consignment,
            reason:          $event->reason,
            requestedByName: $event->requestedByName,
        ));
    }
}
