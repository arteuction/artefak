<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class GeoLocality extends Model
{
    protected $fillable = [
        'geo_municipality_id', 'name', 'slug', 'ekatte', 'type', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(GeoMunicipality::class, 'geo_municipality_id');
    }

    public function metroStops(): HasMany
    {
        return $this->hasMany(MetroStop::class);
    }

    /**
     * Scope: localities within a bounding box (west, south, east, north).
     */
    public function scopeInBbox(Builder $query, float $west, float $south, float $east, float $north): Builder
    {
        return $query
            ->whereBetween('latitude',  [$south, $north])
            ->whereBetween('longitude', [$west,  $east]);
    }

    /**
     * Scope: localities within $radiusMeters of a point (Haversine via SQL).
     * Returns rows ordered by distance ascending with a virtual `distance_m` column.
     */
    public function scopeNearPoint(Builder $query, float $lat, float $lng, int $radiusMeters): Builder
    {
        $earthRadius = 6371000; // metres

        return $query
            ->selectRaw("*, ({$earthRadius} * ACOS(
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
