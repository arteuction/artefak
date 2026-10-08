<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Gallery extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'venue_id',
        'website',
        'contact_email',
        'legal_name',
        'eik',
        'stripe_account_id',
        'status',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function artLots(): HasMany
    {
        return $this->hasMany(ArtLot::class);
    }

    public function sellNowOffers(): HasMany
    {
        return $this->hasMany(SellNowOffer::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
