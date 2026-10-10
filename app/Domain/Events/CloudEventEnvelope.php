<?php

declare(strict_types=1);

namespace App\Domain\Events;

use Ramsey\Uuid\Uuid;

/**
 * Phase 148 — CloudEvents 1.0 envelope.
 *
 * Wraps an internal domain event in a CloudEvents 1.0-compatible structure
 * so that external consumers (webhooks, event buses) receive a standard
 * envelope regardless of which internal event occurred.
 *
 * Mandatory CloudEvents attributes (spec §3.1):
 *   id, source, specversion, type
 *
 * @see https://cloudevents.io/
 * @see https://github.com/cloudevents/spec/blob/v1.0.2/cloudevents/spec.md
 */
final class CloudEventEnvelope
{
    public const SPEC_VERSION = '1.0';

    private function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $specversion,
        public readonly string $type,
        public readonly string $datacontenttype,
        public readonly string $time,
        public readonly array  $data,
    ) {}

    /**
     * Create from an internal domain_events row (or equivalent array).
     *
     * @param array{
     *   id: int,
     *   aggregate_type: string,
     *   aggregate_id: int,
     *   event_type: string,
     *   payload: string,
     *   created_at: string,
     * } $domainEvent
     */
    public static function fromDomainEvent(array $domainEvent, string $appSource = 'https://arteuction.bg'): self
    {
        $payload = json_decode($domainEvent['payload'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

        return new self(
            id:              Uuid::uuid4()->toString(),
            source:          rtrim($appSource, '/') . '/' . strtolower($domainEvent['aggregate_type'])
                             . '/' . $domainEvent['aggregate_id'],
            specversion:     self::SPEC_VERSION,
            type:            'bg.arteuction.' . $domainEvent['event_type'],
            datacontenttype: 'application/json',
            time:            $domainEvent['created_at'],
            data:            $payload,
        );
    }

    public function toArray(): array
    {
        return [
            'specversion'     => $this->specversion,
            'id'              => $this->id,
            'source'          => $this->source,
            'type'            => $this->type,
            'datacontenttype' => $this->datacontenttype,
            'time'            => $this->time,
            'data'            => $this->data,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function validate(): array
    {
        $errors = [];

        if (empty($this->id)) {
            $errors[] = 'id is required';
        }
        if (empty($this->source)) {
            $errors[] = 'source is required';
        }
        if ($this->specversion !== self::SPEC_VERSION) {
            $errors[] = "specversion must be '1.0', got '{$this->specversion}'";
        }
        if (empty($this->type)) {
            $errors[] = 'type is required';
        }
        if (! str_starts_with($this->source, 'http://') && ! str_starts_with($this->source, 'https://')) {
            $errors[] = 'source must be an absolute URI';
        }

        return $errors;
    }
}
