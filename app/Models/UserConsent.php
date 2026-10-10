<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserConsent extends Model
{
    protected $fillable = [
        'user_id',
        'governance_policy_id',
        'consented_at',
        'ip_address',
        'user_agent',
        'withdrawn_at',
    ];

    protected $casts = [
        'consented_at'  => 'datetime',
        'withdrawn_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(GovernancePolicy::class, 'governance_policy_id');
    }

    public function isActive(): bool
    {
        return $this->withdrawn_at === null;
    }
}
