<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReconciliationMismatch extends Model
{
    protected $fillable = [
        'reconciliation_run_id',
        'check_type',
        'stripe_transaction_id',
        'internal_reference',
        'stripe_amount_cents',
        'internal_amount_cents',
        'delta_cents',
        'description',
        'resolution_status',
        'resolution_notes',
        'resolved_at',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
