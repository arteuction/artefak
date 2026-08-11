<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ArtmetroArtifact extends Model
{
    use SoftDeletes;

    protected $table = 'artmetro_artifacts';

    protected $fillable = [
        'exhibition_id', 'sellable_type', 'sellable_id',
        'title', 'description', 'ar_model_url',
        'qr_token', 'qr_version', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
        'qr_version'  => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $artifact): void {
            if (empty($artifact->qr_token)) {
                $artifact->qr_token   = Str::random(48);
                $artifact->qr_version = 1;
            }
        });
    }

    // ── Relations ────────────────────────────────────────────────────

    public function exhibition(): BelongsTo
    {
        return $this->belongsTo(Exhibition::class);
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ArtmetroArtifactScan::class, 'artifact_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ArtmetroVisit::class, 'artifact_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
