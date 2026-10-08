<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted version of a SplitProfile.
 *
 * The domain SplitProfile value object is the computation authority;
 * this model is the audit and versioning record.
 * Settlements snapshot profile_key + version at creation time and are
 * immutable — this model provides the human-readable record of what
 * those numbers meant at that point in time.
 */
final class SplitProfileVersion extends Model
{
    protected $table = 'split_profiles';

    protected $fillable = [
        'profile_key',
        'version',
        'artist_bps',
        'fund_bps',
        'ops_bps',
        'description',
        'status',
        'effective_from',
        'effective_until',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'version'        => 'integer',
        'artist_bps'     => 'integer',
        'fund_bps'       => 'integer',
        'ops_bps'        => 'integer',
        'effective_from' => 'datetime',
        'effective_until'=> 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function sumBps(): int
    {
        return $this->artist_bps + $this->fund_bps + $this->ops_bps;
    }

    public static function activeForKey(string $key): ?self
    {
        return static::where('profile_key', $key)->where('status', 'active')->first();
    }
}
