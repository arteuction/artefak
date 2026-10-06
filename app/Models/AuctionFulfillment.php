<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuctionFulfillment extends Model
{
    protected $fillable = [
        'auction_item_id', 'winner_user_id',
        'shipping_name', 'shipping_line1', 'shipping_line2',
        'shipping_city', 'shipping_postal_code', 'shipping_country',
        'carrier', 'tracking_number',
        'shipped_at', 'delivered_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at'   => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }
}
