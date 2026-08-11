<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AuctionItem extends Model
{
    protected $fillable = [
        'auction_id', 'artwork_id', 'lot_number',
        'reserve_price_cents', 'starting_bid_cents', 'bid_increment_cents',
        'buy_now_price_cents', 'status', 'winning_bid_id',
    ];

    protected function casts(): array
    {
        return [
            'reserve_price_cents'  => 'integer',
            'starting_bid_cents'   => 'integer',
            'bid_increment_cents'  => 'integer',
            'buy_now_price_cents'  => 'integer',
            'winning_bid_id'       => 'integer',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class)->orderByDesc('amount_cents');
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(Bid::class, 'winning_bid_id');
    }

    public function highestBid(): ?Bid
    {
        return $this->bids()->where('status', 'accepted')->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /** Next minimum bid in cents. */
    public function nextBidCents(): int
    {
        $highest = $this->bids()->where('status', 'accepted')->max('amount_cents');

        return $highest
            ? $highest + $this->bid_increment_cents
            : $this->starting_bid_cents;
    }
}
