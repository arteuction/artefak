<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtmetroRouteStop extends Model
{
    protected $table = 'artmetro_route_stops';

    protected $fillable = ['route_id', 'venue_id', 'sort_order', 'notes'];

    protected $casts = ['sort_order' => 'integer'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ArtmetroRoute::class, 'route_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
