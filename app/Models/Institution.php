<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Institution extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'country_code',
        'website_url',
        'api_endpoint',
        'europeana_provider_id',
        'contact_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function artworks(): BelongsToMany
    {
        return $this->belongsToMany(Artwork::class, 'institution_artworks')
            ->withPivot(['relationship', 'start_date', 'end_date', 'notes'])
            ->withTimestamps();
    }
}
