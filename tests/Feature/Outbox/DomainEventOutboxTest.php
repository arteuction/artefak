<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domain\Asset\ActivateConsignment;
use App\Domain\Asset\CreateArtworkRevision;
use App\Domain\Asset\CreateConsignment;
use App\Domain\Asset\PublishArtworkRevision;
use App\Domain\Outbox\AppendDomainEvent;
use App\Domain\Outbox\DomainEventDispatcher;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

final class DomainEventOutboxTest extends TestCase
{
    use RefreshDatabase;

    private User    $user;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user   = User::factory()->create(['role' => 'artist']);
        $this->artwork = Artwork::create([
            'user_id' => $this->user->id,
            'title'   => 'Test Art',
            'slug'    => 'test-art-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    // --- AppendDomainEvent ---

    public function test_append_creates_pending_event(): void
    {
        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        $event = (new AppendDomainEvent())->execute(
            aggregate: $artLot,
            eventType: 'art_lot.activated',
            payload: ['art_lot_id' => $artLot->id],
        );

        $this->assertSame('ArtLot', $event->aggregate_type);
        $this->assertSame($artLot->id, $event->aggregate_id);
        $this->assertSame('art_lot.activated', $event->event_type);
        $this->assertSame('pending', $event->status);
        $this->assertSame(0, (int) $event->attempt);
        $this->assertTrue($event->isPending());
    }

    public function test_append_with_explicit_idempotency_key(): void
    {
        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute(
            aggregate: $artLot,
            eventType: 'art_lot.activated',
            payload: [],
            idempotencyKey: 'my-idempotency-key-123',
        );

        $this->assertDatabaseHas('domain_events', [
            'idempotency_key' => 'my-idempotency-key-123',
        ]);
    }

    public function test_duplicate_idempotency_key_throws(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        $key = 'dup-key-' . uniqid();

        (new AppendDomainEvent())->execute($artLot, 'art_lot.test', [], $key);
        (new AppendDomainEvent())->execute($artLot, 'art_lot.test', [], $key);
    }

    public function test_empty_event_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event_type must not be empty');

        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($artLot, '', []);
    }

    // --- DomainEventDispatcher ---

    public function test_dispatcher_dispatches_pending_events(): void
    {
        Event::fake();

        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($artLot, 'art_lot.test_dispatch', ['x' => 1]);

        $dispatcher = new DomainEventDispatcher(app(\Illuminate\Contracts\Events\Dispatcher::class));
        $count = $dispatcher->dispatchPending();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'art_lot.test_dispatch',
            'status'     => 'dispatched',
        ]);

        Event::assertDispatched('art_lot.test_dispatch');
    }

    public function test_dispatcher_marks_failed_after_max_attempts(): void
    {
        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($artLot, 'art_lot.will_fail', []);

        // Register a listener that always throws
        app(\Illuminate\Contracts\Events\Dispatcher::class)
            ->listen('art_lot.will_fail', function () {
                throw new \RuntimeException('Simulated failure');
            });

        $dispatcher = new DomainEventDispatcher(app(\Illuminate\Contracts\Events\Dispatcher::class));

        // Drive it past MAX_ATTEMPTS (5) by manually bumping attempt
        $row = DomainEvent::where('event_type', 'art_lot.will_fail')->first();
        $row->update(['attempt' => 4]); // one more dispatch => attempt 5 => failed

        $dispatcher->dispatchPending();

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'art_lot.will_fail',
            'status'     => 'failed',
        ]);
    }

    public function test_dispatcher_skips_events_with_future_next_attempt_at(): void
    {
        Event::fake();

        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($artLot, 'art_lot.deferred', []);

        // Push next_attempt_at into the future
        DomainEvent::where('event_type', 'art_lot.deferred')
            ->update(['next_attempt_at' => now()->addHour()]);

        $dispatcher = new DomainEventDispatcher(app(\Illuminate\Contracts\Events\Dispatcher::class));
        $count = $dispatcher->dispatchPending();

        $this->assertSame(0, $count);
        Event::assertNotDispatched('art_lot.deferred');
    }

    public function test_reclaim_stale_lease_resets_processing_rows(): void
    {
        $artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($artLot, 'art_lot.stale_lease', []);

        // Simulate a stuck processing row with an expired lease
        DomainEvent::where('event_type', 'art_lot.stale_lease')->update([
            'status'                => 'processing',
            'processing_started_at' => now()->subMinutes(5),
        ]);

        $dispatcher = new DomainEventDispatcher(app(\Illuminate\Contracts\Events\Dispatcher::class));
        $reclaimed = $dispatcher->reclaimStaleLease();

        $this->assertSame(1, $reclaimed);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'art_lot.stale_lease',
            'status'     => 'pending',
        ]);
    }

    // --- Integration: domain actions emit events ---

    public function test_activate_consignment_appends_domain_event(): void
    {
        $owner     = User::factory()->create(['role' => 'artist']);
        $consignor = User::factory()->create(['role' => 'artist']);

        $consignment = (new CreateConsignment())->execute(
            artwork: $this->artwork,
            owner: $owner,
            consignor: $consignor,
        );

        (new ActivateConsignment())->execute($consignment);

        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'Consignment',
            'aggregate_id'   => $consignment->id,
            'event_type'     => 'consignment.activated',
            'status'         => 'pending',
        ]);
    }

    public function test_publish_artwork_revision_appends_domain_event(): void
    {
        $revision = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->user,
            title: 'Outbox Title',
            reason: 'initial',
        );

        (new PublishArtworkRevision())->execute($revision);

        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'ArtworkRevision',
            'aggregate_id'   => $revision->id,
            'event_type'     => 'artwork_revision.published',
            'status'         => 'pending',
        ]);
    }
}
