<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Reserve extends Model
{
    protected $fillable = [
        'auction_item_id',
        'reserve_price_cents',
        'highest_bid_cents',
        'status',
        'decided_by',
        'decided_at',
        'counter_offer_cents',
        'counter_offer_expires_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'reserve_price_cents'      => 'integer',
            'highest_bid_cents'        => 'integer',
            'counter_offer_cents'      => 'integer',
            'decided_at'               => 'datetime',
            'counter_offer_expires_at' => 'datetime',
        ];
    }

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'not_reached';
    }

    public function shortfallCents(): int
    {
        return $this->reserve_price_cents - $this->highest_bid_cents;
    }
}
