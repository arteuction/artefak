<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Venue extends Model
{
    protected $fillable = [
        'name', 'slug', 'type',
        'geo_locality_id', 'metro_stop_id',
        'address', 'latitude', 'longitude',
        'phone', 'email', 'website',
        'opening_hours', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude'      => 'decimal:7',
            'longitude'     => 'decimal:7',
            'opening_hours' => 'array',
            'is_active'     => 'boolean',
        ];
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(GeoLocality::class, 'geo_locality_id');
    }

    public function geoLocality(): BelongsTo
    {
        return $this->locality();
    }

    public function metroStop(): BelongsTo
    {
        return $this->belongsTo(MetroStop::class, 'metro_stop_id');
    }

    public function exhibitions(): HasMany
    {
        return $this->hasMany(Exhibition::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Venues within $radiusMeters of a point (Haversine). */
    public function scopeNearPoint(Builder $query, float $lat, float $lng, int $radiusMeters): Builder
    {
        $r = 6371000;

        return $query
            ->selectRaw("*, ({$r} * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                COS(RADIANS(longitude) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(latitude))
            )) AS distance_m", [$lat, $lng, $lat])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->havingRaw('distance_m <= ?', [$radiusMeters])
            ->orderBy('distance_m');
    }
}
