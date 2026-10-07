<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MaxBid extends Model
{
    protected $fillable = [
        'auction_item_id', 'user_id',
        'ceiling_cents', 'currency',
        'status', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'ceiling_cents' => 'integer',
            'cancelled_at'  => 'datetime',
        ];
    }

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Proxy bids that were placed on behalf of this max bid. */
    public function proxyBids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }
}
