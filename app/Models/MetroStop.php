<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MetroStop extends Model
{
    protected $fillable = ['code', 'name', 'latitude', 'longitude', 'geo_locality_id'];

    protected function casts(): array
    {
        return [
            'latitude'  => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(GeoLocality::class, 'geo_locality_id');
    }

    public function displays(): HasMany
    {
        return $this->hasMany(MetroStopDisplay::class);
    }

    public function lines(): BelongsToMany
    {
        return $this->belongsToMany(MetroLine::class, 'metro_stop_lines')
            ->withPivot('sort_order');
    }

    /**
     * Scope: stops within $radiusMeters of a point (Haversine via SQL).
     */
    public function scopeNearPoint(Builder $query, float $lat, float $lng, int $radiusMeters): Builder
    {
        $earthRadius = 6371000;

        return $query
            ->selectRaw("*, ({$earthRadius} * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                COS(RADIANS(longitude) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(latitude))
            )) AS distance_m", [$lat, $lng, $lat])
            ->havingRaw('distance_m <= ?', [$radiusMeters])
            ->orderBy('distance_m');
    }
}
