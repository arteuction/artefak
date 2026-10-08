<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Models\DomainEvent;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AppendDomainEvent
{
    /**
     * Write one domain event row inside the caller's DB transaction.
     *
     * @param  Model  $aggregate   The aggregate root (ArtLot, Consignment, etc.)
     * @param  string $eventType   Dot-namespaced type, e.g. 'art_lot.sold'
     * @param  array  $payload     Frozen snapshot — caller controls what goes in
     * @param  string|null $idempotencyKey  If null, generated from aggregate + type + id
     */
    public function execute(
        Model   $aggregate,
        string  $eventType,
        array   $payload,
        ?string $idempotencyKey = null,
        int     $eventVersion   = 1,
    ): DomainEvent {
        if (empty($eventType)) {
            throw new InvalidArgumentException('event_type must not be empty.');
        }

        $aggregateType = class_basename($aggregate);
        $aggregateId   = $aggregate->getKey();

        $key = $idempotencyKey
            ?? hash('xxh3', "{$aggregateType}:{$aggregateId}:{$eventType}:" . microtime());

        return DomainEvent::create([
            'aggregate_type'  => $aggregateType,
            'aggregate_id'    => $aggregateId,
            'event_type'      => $eventType,
            'event_version'   => $eventVersion,
            'payload'         => $payload,
            'idempotency_key' => $key,
            'status'          => 'pending',
        ]);
    }
}
