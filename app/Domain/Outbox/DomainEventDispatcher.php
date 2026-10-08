<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Models\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Throwable;

final class DomainEventDispatcher
{
    // Lease prevents two workers from dispatching the same row simultaneously
    private const LEASE_SECONDS  = 30;
    private const MAX_ATTEMPTS   = 5;
    // Exponential back-off base in seconds
    private const BACKOFF_BASE   = 15;

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * Claim up to $limit pending rows, dispatch each, and return the count dispatched.
     */
    public function dispatchPending(int $limit = 100): int
    {
        $dispatched = 0;
        $now = Carbon::now();

        $rows = DomainEvent::where('status', 'pending')
            ->where(function ($q) use ($now) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $event) {
            // Acquire processing lease (optimistic: skip if already grabbed)
            $claimed = DomainEvent::where('id', $event->id)
                ->where('status', 'pending')
                ->update([
                    'status'                 => 'processing',
                    'processing_started_at'  => $now,
                    'attempt'                => $event->attempt + 1,
                ]);

            if (! $claimed) {
                continue;
            }

            try {
                $this->events->dispatch($event->event_type, [$event]);

                $event->update([
                    'status'        => 'dispatched',
                    'dispatched_at' => Carbon::now(),
                    'last_error'    => null,
                ]);

                $dispatched++;
            } catch (Throwable $e) {
                $attempt = $event->attempt + 1;

                if ($attempt >= self::MAX_ATTEMPTS) {
                    $event->update([
                        'status'     => 'failed',
                        'last_error' => $e->getMessage(),
                    ]);
                } else {
                    $backoff = self::BACKOFF_BASE * (2 ** ($attempt - 1));

                    $event->update([
                        'status'          => 'pending',
                        'last_error'      => $e->getMessage(),
                        'next_attempt_at' => Carbon::now()->addSeconds($backoff),
                    ]);
                }
            }
        }

        return $dispatched;
    }

    /**
     * Reclaim processing rows whose lease has expired (worker died mid-dispatch).
     */
    public function reclaimStaleLease(): int
    {
        $cutoff = Carbon::now()->subSeconds(self::LEASE_SECONDS);

        return DomainEvent::where('status', 'processing')
            ->where('processing_started_at', '<', $cutoff)
            ->update([
                'status'                => 'pending',
                'processing_started_at' => null,
                'next_attempt_at'       => Carbon::now(),
            ]);
    }
}
