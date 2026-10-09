<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectedAccountPayout extends Model
{
    protected $fillable = [
        'stripe_payout_id',
        'stripe_account_id',
        'stripe_event_id',
        'amount_cents',
        'currency',
        'status',
        'failure_code',
        'failure_message',
        'arrival_date',
    ];

    protected $casts = [
        'arrival_date' => 'datetime',
        'amount_cents' => 'integer',
    ];
}
