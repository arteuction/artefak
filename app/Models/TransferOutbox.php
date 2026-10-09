<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TransferOutbox extends Model
{
    protected $table = 'transfer_outbox';

    protected $fillable = [
        'settlement_line_id', 'stripe_account_id', 'amount_cents', 'currency',
        'stripe_idempotency_key', 'status', 'attempt', 'last_error',
        'next_attempt_at', 'stripe_transfer_id', 'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents'   => 'integer',
            'attempt'        => 'integer',
            'next_attempt_at' => 'datetime',
            'dispatched_at'  => 'datetime',
        ];
    }

    public function settlementLine(): BelongsTo
    {
        return $this->belongsTo(SettlementLine::class);
    }
}
