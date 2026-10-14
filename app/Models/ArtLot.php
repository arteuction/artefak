<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class ArtLot extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'sale_mode', 'split_profile_key', 'reserve_price_cents', 'buy_now_price_cents'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('art_lot');
    }
    protected $fillable = [
        'artwork_id', 'consignor_id', 'sale_mode', 'status',
        'reserve_price_cents', 'starting_bid_cents', 'buy_now_price_cents', 'currency',
        'split_profile_key',
        'gallery_id',
        'consignment_id',
        'artwork_revision_id',
        'estimate_low_cents', 'estimate_high_cents',
        'published_at', 'closed_at',
        'buy_now_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reserve_price_cents'  => 'integer',
            'starting_bid_cents'   => 'integer',
            'buy_now_price_cents'  => 'integer',
            'estimate_low_cents'   => 'integer',
            'estimate_high_cents'  => 'integer',
            'published_at'         => 'datetime',
            'closed_at'            => 'datetime',
            'buy_now_expires_at'   => 'datetime',
        ];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consignor_id');
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function sellNowOffers(): HasMany
    {
        return $this->hasMany(SellNowOffer::class);
    }

    public function auctionItem(): HasOne
    {
        return $this->hasOne(AuctionItem::class);
    }

    public function auctionItems(): HasMany
    {
        return $this->hasMany(AuctionItem::class);
    }

    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(OwnershipTransfer::class);
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function artworkRevision(): BelongsTo
    {
        return $this->belongsTo(ArtworkRevision::class, 'artwork_revision_id');
    }
}
