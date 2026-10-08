<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ImpactProject extends Model
{
    protected $attributes = [
        'funding_actual_cents' => 0,
        'currency'             => 'EUR',
        'status'               => 'planned',
    ];

    protected $fillable = [
        'title',
        'slug',
        'description',
        'sdg_number',
        'funding_target_cents',
        'funding_actual_cents',
        'currency',
        'status',
        'starts_on',
        'ends_on',
        'partners',
    ];

    protected $casts = [
        'sdg_number'           => 'integer',
        'funding_target_cents' => 'integer',
        'funding_actual_cents' => 'integer',
        'starts_on'            => 'date',
        'ends_on'              => 'date',
        'partners'             => 'array',
    ];

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    public function fundingProgressBps(): int
    {
        if ($this->funding_target_cents === null || $this->funding_target_cents === 0) {
            return 0;
        }

        return (int) min(10000, intdiv($this->funding_actual_cents * 10000, $this->funding_target_cents));
    }

    public function isFullyFunded(): bool
    {
        return $this->funding_target_cents !== null
            && $this->funding_actual_cents >= $this->funding_target_cents;
    }
}
