<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SellNowOffer extends Model
{
    protected $fillable = [
        'art_lot_id',
        'buyer_id',
        'gallery_id',
        'offered_price_cents',
        'counter_price_cents',
        'agreed_price_cents',
        'currency',
        'status',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'offered_price_cents' => 'integer',
        'counter_price_cents' => 'integer',
        'agreed_price_cents'  => 'integer',
        'expires_at'          => 'datetime',
    ];

    public function artLot(): BelongsTo
    {
        return $this->belongsTo(ArtLot::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['submitted', 'countered'], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['accepted', 'rejected', 'expired', 'paid', 'delivered', 'closed'], true);
    }
}
