<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Models\ConsumerInbox;
use App\Models\DomainEvent;
use Illuminate\Database\UniqueConstraintViolationException;

final class RecordConsumerEvent
{
    /**
     * Mark a domain event as processed by a consumer (idempotent).
     *
     * Returns the inbox row whether it was just created or already existed.
     * Callers should check ->wasRecentlyCreated to decide whether to apply
     * side-effects: if false, the event was already processed and the
     * side-effect must be skipped.
     */
    public function execute(
        string $consumer,
        DomainEvent $event,
        ?string $result = null,
    ): ConsumerInbox {
        try {
            return ConsumerInbox::create([
                'consumer'        => $consumer,
                'domain_event_id' => $event->id,
                'event_type'      => $event->event_type,
                'processed_at'    => now(),
                'result'          => $result,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already processed — return the existing row, wasRecentlyCreated = false
            return ConsumerInbox::where('consumer', $consumer)
                ->where('domain_event_id', $event->id)
                ->firstOrFail();
        }
    }
}
