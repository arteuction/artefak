<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArtmetroRoute extends Model
{
    protected $table = 'artmetro_routes';

    protected $fillable = [
        'title', 'slug', 'description',
        'difficulty', 'walking_distance_km', 'accessible', 'is_published',
    ];

    protected $casts = [
        'accessible'           => 'boolean',
        'is_published'         => 'boolean',
        'walking_distance_km'  => 'decimal:2',
    ];

    public function stops(): HasMany
    {
        return $this->hasMany(ArtmetroRouteStop::class, 'route_id')
                    ->orderBy('sort_order');
    }

    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'artmetro_route_stops', 'route_id', 'venue_id')
                    ->withPivot('sort_order', 'notes')
                    ->orderByPivot('sort_order');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeAccessible($query)
    {
        return $query->where('accessible', true);
    }
}
