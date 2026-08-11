<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

final class GeoRegion extends Model
{
    protected $fillable = ['name', 'slug', 'code', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return [
            'latitude'  => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function municipalities(): HasMany
    {
        return $this->hasMany(GeoMunicipality::class);
    }

    public function localities(): HasManyThrough
    {
        return $this->hasManyThrough(GeoLocality::class, GeoMunicipality::class);
    }
}
