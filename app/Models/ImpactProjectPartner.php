<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImpactProjectPartner extends Model
{
    public const ROLES = ['lead', 'co_funder', 'beneficiary', 'implementation', 'other'];

    protected $fillable = [
        'impact_project_id',
        'organization_name',
        'organization_type',
        'role',
        'url',
        'started_on',
        'ended_on',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'started_on' => 'date',
        'ended_on'   => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ImpactProject::class, 'impact_project_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
