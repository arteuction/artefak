<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ApiClient extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'key_hash',
        'key_prefix',
        'user_id',
        'institution_id',
        'scopes',
        'rate_limit_per_minute',
        'is_active',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'scopes'                => 'array',
        'is_active'             => 'boolean',
        'last_used_at'          => 'datetime',
        'expires_at'            => 'datetime',
        'rate_limit_per_minute' => 'integer',
    ];

    /** Generate a new API key, returning [client, plaintext_key]. */
    public static function generate(
        string $name,
        array $scopes = [],
        ?int $userId = null,
        ?int $institutionId = null,
        ?int $rateLimitPerMinute = null,
    ): array {
        $plaintext = 'artk_' . Str::random(40);
        $prefix    = substr($plaintext, 0, 12);
        $hash      = hash('sha256', $plaintext);

        $client = self::create([
            'name'                   => $name,
            'key_hash'               => $hash,
            'key_prefix'             => $prefix,
            'user_id'                => $userId,
            'institution_id'         => $institutionId,
            'scopes'                 => $scopes,
            'rate_limit_per_minute'  => $rateLimitPerMinute,
        ]);

        return [$client, $plaintext];
    }

    /** Verify a plaintext key against stored hash. */
    public static function findByKey(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);
        return self::where('key_hash', $hash)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
