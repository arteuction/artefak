<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Consignment extends Model
{
    protected $fillable = [
        'artwork_id',
        'owner_id',
        'consignor_id',
        'gallery_id',
        'commission_bps',
        'status',
        'starts_at',
        'ends_at',
        'notes',
    ];

    protected $casts = [
        'commission_bps' => 'integer',
        'starts_at'      => 'datetime',
        'ends_at'        => 'datetime',
    ];

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consignor_id');
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function artLots(): HasMany
    {
        return $this->hasMany(ArtLot::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function ownerIsConsignor(): bool
    {
        return $this->owner_id === $this->consignor_id;
    }

    /** Commission rate as a decimal fraction, e.g. 0.15 for 1500 bps. */
    public function commissionRate(): float
    {
        return $this->commission_bps / 10000;
    }
}
