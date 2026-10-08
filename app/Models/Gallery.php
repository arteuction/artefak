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

    public function staff(): HasMany
    {
        return $this->hasMany(GalleryStaff::class);
    }

    public function activeStaff(): HasMany
    {
        return $this->hasMany(GalleryStaff::class)->where('status', 'active');
    }

    public function staffWithRole(string $role): HasMany
    {
        return $this->hasMany(GalleryStaff::class)->where('role', $role)->where('status', 'active');
    }

    /** True if the given user holds ANY active role at this gallery. */
    public function hasMember(User $user): bool
    {
        return $this->staff()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /** True if the given user holds the specified active role. */
    public function hasRole(User $user, string $role): bool
    {
        return $this->staff()
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->where('status', 'active')
            ->exists();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
