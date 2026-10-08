<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReconciliationRun extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'internal_gross_cents',
        'internal_transfer_cents',
        'internal_settlement_count',
        'stripe_received_cents',
        'stripe_transferred_cents',
        'delta_received_cents',
        'delta_transferred_cents',
        'status',
        'notes',
        'run_by',
        'completed_at',
    ];

    protected $attributes = [
        'status'                     => 'running',
        'internal_gross_cents'       => 0,
        'internal_transfer_cents'    => 0,
        'internal_settlement_count'  => 0,
    ];

    protected $casts = [
        'period_start'               => 'date',
        'period_end'                 => 'date',
        'internal_gross_cents'       => 'integer',
        'internal_transfer_cents'    => 'integer',
        'internal_settlement_count'  => 'integer',
        'stripe_received_cents'      => 'integer',
        'stripe_transferred_cents'   => 'integer',
        'delta_received_cents'       => 'integer',
        'delta_transferred_cents'    => 'integer',
        'completed_at'               => 'datetime',
    ];

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }

    public function isMismatched(): bool
    {
        return $this->status === 'mismatched';
    }
}
