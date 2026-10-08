<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ConsignmentChangesRequested;
use App\Events\OfferAccepted;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Events\Dispatcher;

/**
 * Subscribes to raw domain-event string names dispatched by DomainEventDispatcher
 * and re-dispatches them as strongly-typed synthetic Laravel events.
 */
final class DomainEventConsumer
{
    public function handleOfferAccepted(string $eventType, array $args): void
    {
        /** @var DomainEvent $domainEvent */
        $domainEvent = $args[0] ?? null;
        if (! $domainEvent instanceof DomainEvent) {
            return;
        }

        $offerId = (int) ($domainEvent->payload['offer_id'] ?? 0);
        if ($offerId > 0) {
            OfferAccepted::dispatch($offerId);
        }
    }

    public function handleConsignmentChangesRequested(string $eventType, array $args): void
    {
        /** @var DomainEvent $domainEvent */
        $domainEvent = $args[0] ?? null;
        if (! $domainEvent instanceof DomainEvent) {
            return;
        }

        $payload       = $domainEvent->payload;
        $consignmentId = (int) $domainEvent->aggregate_id;
        $reason        = (string) ($payload['reason'] ?? '');
        $requestedById = (int) ($payload['requested_by'] ?? 0);

        $requestedByName = $requestedById > 0
            ? (User::find($requestedById)?->name ?? 'Unknown')
            : 'Unknown';

        ConsignmentChangesRequested::dispatch($consignmentId, $reason, $requestedByName);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('offer.accepted',                [$this, 'handleOfferAccepted']);
        $events->listen('consignment.changes_requested', [$this, 'handleConsignmentChangesRequested']);
    }
}
