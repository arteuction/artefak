<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class AuctionItem extends Model
{
    protected $fillable = [
        'auction_id', 'art_lot_id', 'lot_number',
        'bid_increment_cents', 'status', 'winning_bid_id',
        'payment_deadline', 'fulfillment_status', 'winner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'bid_increment_cents' => 'integer',
            'winning_bid_id'      => 'integer',
            'payment_deadline'    => 'datetime',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function artLot(): BelongsTo
    {
        return $this->belongsTo(ArtLot::class);
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

    public function fulfillment(): HasOne
    {
        return $this->hasOne(AuctionFulfillment::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Server-authoritative open check.
     *
     * Status alone is insufficient: there is a race window between the moment
     * the auction's ends_at passes and the time CloseAuctionItem runs.  Any
     * bid accepted in that window would be after the hammer fell.
     *
     * Rule: status must be 'open' AND auction.ends_at must be in the future.
     * The browser timer is display-only and MUST NOT be the gate.
     */
    public function isOpenForBidding(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $endsAt = $this->auction?->ends_at;
        if ($endsAt === null) {
            return false;
        }

        return now()->lt($endsAt);
    }

    /** Next minimum bid in cents. Starting bid lives on ArtLot. */
    public function nextBidCents(): int
    {
        $highest   = $this->bids()->where('status', 'accepted')->max('amount_cents');
        $increment = $this->bid_increment_cents
                  ?? $this->auction?->ruleset?->default_bid_increment_cents
                  ?? 1000;

        return $highest
            ? $highest + $increment
            : ($this->artLot->starting_bid_cents ?? 0);
    }
}
