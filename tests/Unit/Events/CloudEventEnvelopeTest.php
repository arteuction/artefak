<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Domain\Events\CloudEventEnvelope;
use Tests\TestCase;

/**
 * Phase 148 — CloudEvents 1.0 envelope conformance.
 */
class CloudEventEnvelopeTest extends TestCase
{
    private array $sampleEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleEvent = [
            'id'             => 42,
            'aggregate_type' => 'SellNowOffer',
            'aggregate_id'   => 17,
            'event_type'     => 'offer.accepted',
            'payload'        => json_encode(['agreed_price_cents' => 50000, 'currency' => 'EUR']),
            'created_at'     => '2026-10-10T12:00:00+00:00',
        ];
    }

    public function test_specversion_is_1_0(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertSame('1.0', $envelope->specversion);
    }

    public function test_id_is_uuid_v4(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $envelope->id,
        );
    }

    public function test_two_envelopes_have_different_ids(): void
    {
        $a = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $b = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_type_is_prefixed_with_bg_arteuction(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertSame('bg.arteuction.offer.accepted', $envelope->type);
    }

    public function test_source_is_absolute_uri_containing_aggregate(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertStringStartsWith('https://', $envelope->source);
        $this->assertStringContainsString('/sellnowoffer/17', $envelope->source);
    }

    public function test_datacontenttype_is_application_json(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertSame('application/json', $envelope->datacontenttype);
    }

    public function test_time_is_preserved(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertSame('2026-10-10T12:00:00+00:00', $envelope->time);
    }

    public function test_data_contains_decoded_payload(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertSame(50000, $envelope->data['agreed_price_cents']);
        $this->assertSame('EUR', $envelope->data['currency']);
    }

    public function test_to_array_contains_all_mandatory_fields(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $array    = $envelope->toArray();

        foreach (['specversion', 'id', 'source', 'type', 'datacontenttype', 'time', 'data'] as $field) {
            $this->assertArrayHasKey($field, $array, "Missing mandatory field: {$field}");
        }
    }

    public function test_to_json_is_valid_json(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $json     = $envelope->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('1.0', $decoded['specversion']);
    }

    public function test_validate_returns_no_errors_for_valid_envelope(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent);
        $this->assertEmpty($envelope->validate());
    }

    public function test_custom_source_is_used(): void
    {
        $envelope = CloudEventEnvelope::fromDomainEvent($this->sampleEvent, 'https://staging.arteuction.bg');
        $this->assertStringStartsWith('https://staging.arteuction.bg/', $envelope->source);
    }

    public function test_empty_payload_produces_empty_data_array(): void
    {
        $event    = array_merge($this->sampleEvent, ['payload' => '{}']);
        $envelope = CloudEventEnvelope::fromDomainEvent($event);
        $this->assertSame([], $envelope->data);
    }
}
