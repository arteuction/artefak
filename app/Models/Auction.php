<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

final class Auction extends Model
{
    protected $fillable = [
        'title', 'slug', 'venue_id', 'starts_at', 'ends_at',
        'status', 'currency', 'description',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuctionItem::class)->orderBy('lot_number');
    }

    public function exhibitions(): HasMany
    {
        return $this->hasMany(Exhibition::class);
    }

    public function bids(): HasManyThrough
    {
        return $this->hasManyThrough(Bid::class, AuctionItem::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'live');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('starts_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }
}
