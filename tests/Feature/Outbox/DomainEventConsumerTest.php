<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Events\ConsignmentChangesRequested;
use App\Events\OfferAccepted;
use App\Listeners\DomainEventConsumer;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class DomainEventConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_accepted_string_event_dispatches_typed_event(): void
    {
        Event::fake([OfferAccepted::class]);

        $row = new DomainEvent();
        $row->aggregate_type = 'ArtLot';
        $row->aggregate_id   = 1;
        $row->payload        = ['offer_id' => 42, 'buyer_id' => 7, 'agreed_price_cents' => 5000];

        $consumer = new DomainEventConsumer();
        $consumer->handleOfferAccepted('offer.accepted', [$row]);

        Event::assertDispatched(OfferAccepted::class, function (OfferAccepted $e): bool {
            return $e->offerId === 42;
        });
    }

    public function test_consignment_changes_requested_dispatches_typed_event(): void
    {
        Event::fake([ConsignmentChangesRequested::class]);

        $requester = User::factory()->create(['name' => 'Curator Bob']);

        $row = new DomainEvent();
        $row->aggregate_type = 'Consignment';
        $row->aggregate_id   = 99;
        $row->payload        = [
            'requested_by' => $requester->id,
            'reason'       => 'Missing provenance',
            'gallery_id'   => 5,
        ];

        $consumer = new DomainEventConsumer();
        $consumer->handleConsignmentChangesRequested('consignment.changes_requested', [$row]);

        Event::assertDispatched(ConsignmentChangesRequested::class, function (ConsignmentChangesRequested $e) use ($requester): bool {
            return $e->consignmentId === 99
                && $e->reason === 'Missing provenance'
                && $e->requestedByName === $requester->name;
        });
    }

    public function test_consumer_ignores_non_domain_event_payload(): void
    {
        Event::fake([OfferAccepted::class]);

        $consumer = new DomainEventConsumer();
        $consumer->handleOfferAccepted('offer.accepted', ['not-a-domain-event']);

        Event::assertNotDispatched(OfferAccepted::class);
    }
}
