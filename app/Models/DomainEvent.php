<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DomainEvent extends Model
{
    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'event_version',
        'payload',
        'idempotency_key',
        'status',
        'attempt',
        'last_error',
        'next_attempt_at',
        'processing_started_at',
        'dispatched_at',
    ];

    protected $casts = [
        'payload'               => 'array',
        'event_version'         => 'integer',
        'attempt'               => 'integer',
        'next_attempt_at'       => 'datetime',
        'processing_started_at' => 'datetime',
        'dispatched_at'         => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDispatched(): bool
    {
        return $this->status === 'dispatched';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
