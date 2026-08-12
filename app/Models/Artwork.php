<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Artwork extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'title', 'slug', 'medium', 'dimensions',
        'year_created', 'description', 'provenance',
        'is_original', 'edition_number', 'edition_total',
        'ar_model_url', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_original'    => 'boolean',
            'year_created'   => 'integer',
            'edition_number' => 'integer',
            'edition_total'  => 'integer',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auctionItems(): HasMany
    {
        return $this->hasMany(AuctionItem::class);
    }

    public function sdgClaims(): HasMany
    {
        return $this->hasMany(ArtworkSdgClaim::class);
    }

    public function approvedSdgClaims(): HasMany
    {
        return $this->hasMany(ArtworkSdgClaim::class)->where('status', 'approved');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeListed(Builder $query): Builder
    {
        return $query->whereIn('status', ['listed', 'in_auction']);
    }
}
