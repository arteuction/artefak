<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OwnershipTransfer extends Model
{
    protected $fillable = [
        'art_lot_id',
        'from_user_id',
        'to_user_id',
        'auction_item_id',
        'sell_now_offer_id',
        'transfer_price_cents',
        'currency',
        'channel',
        'transferred_at',
        'notes',
    ];

    protected $casts = [
        'transfer_price_cents' => 'integer',
        'transferred_at'       => 'datetime',
    ];

    public function artLot(): BelongsTo
    {
        return $this->belongsTo(ArtLot::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }

    public function sellNowOffer(): BelongsTo
    {
        return $this->belongsTo(SellNowOffer::class);
    }
}
