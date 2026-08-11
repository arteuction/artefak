<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GeoMunicipality extends Model
{
    protected $fillable = ['geo_region_id', 'name', 'slug', 'code', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return [
            'latitude'  => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(GeoRegion::class, 'geo_region_id');
    }

    public function localities(): HasMany
    {
        return $this->hasMany(GeoLocality::class);
    }
}
