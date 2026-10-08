<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class DispatchDomainEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_pending_events(): void
    {
        Event::fake();

        DomainEvent::create([
            'aggregate_type'  => 'test',
            'aggregate_id'    => 1,
            'event_type'      => 'test.happened',
            'payload'         => ['x' => 1],
            'status'          => 'pending',
            'idempotency_key' => 'test-dispatch-1',
        ]);

        $this->artisan('outbox:dispatch')
            ->assertSuccessful();

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'test.happened',
            'status'     => 'dispatched',
        ]);
    }

    public function test_command_reclaims_stale_processing_leases(): void
    {
        DomainEvent::create([
            'aggregate_type'         => 'test',
            'aggregate_id'           => 2,
            'event_type'             => 'test.stale',
            'payload'                => [],
            'status'                 => 'processing',
            'idempotency_key'        => 'test-stale-1',
            'processing_started_at'  => now()->subMinutes(5),
        ]);

        $this->artisan('outbox:dispatch')
            ->assertSuccessful();

        // Should be reclaimed to pending (then dispatched)
        $event = DomainEvent::where('event_type', 'test.stale')->first();
        $this->assertNotSame('processing', $event->status);
    }

    public function test_command_with_no_pending_events_exits_cleanly(): void
    {
        $this->artisan('outbox:dispatch')
            ->assertSuccessful()
            ->expectsOutputToContain('Dispatched 0');
    }
}
