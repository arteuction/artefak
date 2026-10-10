<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GovernancePolicy extends Model
{
    protected $fillable = [
        'type',
        'version',
        'effective_from',
        'content_url',
        'content_text',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'is_active'      => 'boolean',
    ];

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    /** Return the active policy of a given type, or null. */
    public static function active(string $type): ?self
    {
        return self::where('type', $type)
            ->where('is_active', true)
            ->latest('effective_from')
            ->first();
    }
}
