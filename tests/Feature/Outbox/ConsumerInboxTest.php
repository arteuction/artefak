<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domain\Outbox\AppendDomainEvent;
use App\Domain\Outbox\RecordConsumerEvent;
use App\Models\Artwork;
use App\Models\ArtLot;
use App\Models\ConsumerInbox;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConsumerInboxTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(string $eventType = 'test.event'): DomainEvent
    {
        $user    = User::create(['name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'x', 'role' => 'seller']);
        $artwork = Artwork::create(['user_id' => $user->id, 'title' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'listed']);
        $lot     = ArtLot::create(['artwork_id' => $artwork->id, 'status' => 'active', 'sale_mode' => 'sell_now', 'currency' => 'EUR']);

        return (new AppendDomainEvent())->execute(
            aggregate: $lot,
            eventType: $eventType,
            payload:   ['test' => true],
        );
    }

    public function test_record_consumer_event_creates_inbox_row(): void
    {
        $event  = $this->makeEvent();
        $action = new RecordConsumerEvent();

        $inbox = $action->execute('artmetro', $event, 'applied');

        $this->assertTrue($inbox->wasRecentlyCreated);
        $this->assertSame('artmetro', $inbox->consumer);
        $this->assertSame($event->id, $inbox->domain_event_id);
        $this->assertSame('test.event', $inbox->event_type);
        $this->assertSame('applied', $inbox->result);
    }

    public function test_record_consumer_event_is_idempotent(): void
    {
        $event  = $this->makeEvent();
        $action = new RecordConsumerEvent();

        $first  = $action->execute('artmetro', $event);
        $second = $action->execute('artmetro', $event);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ConsumerInbox::count());
    }

    public function test_different_consumers_can_process_same_event(): void
    {
        $event  = $this->makeEvent();
        $action = new RecordConsumerEvent();

        $a = $action->execute('artmetro', $event);
        $b = $action->execute('analytics', $event);

        $this->assertTrue($a->wasRecentlyCreated);
        $this->assertTrue($b->wasRecentlyCreated);
        $this->assertSame(2, ConsumerInbox::count());
    }

    public function test_same_consumer_can_process_different_events(): void
    {
        $event1 = $this->makeEvent('event.one');
        $event2 = $this->makeEvent('event.two');
        $action = new RecordConsumerEvent();

        $action->execute('artmetro', $event1);
        $action->execute('artmetro', $event2);

        $this->assertSame(2, ConsumerInbox::count());
    }

    public function test_inbox_row_stores_processed_at(): void
    {
        $event = $this->makeEvent();
        $inbox = (new RecordConsumerEvent())->execute('artmetro', $event);

        $this->assertNotNull($inbox->processed_at);
    }

    public function test_domain_event_relation_works(): void
    {
        $event = $this->makeEvent();
        $inbox = (new RecordConsumerEvent())->execute('artmetro', $event);

        $related = $inbox->domainEvent;
        $this->assertSame($event->id, $related->id);
    }
}
