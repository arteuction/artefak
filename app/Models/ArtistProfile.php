<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ArtistProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'display_name', 'slug', 'bio',
        'website', 'instagram_handle',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'terms_accepted_at', 'terms_version',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at'      => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ArtistApplication::class);
    }

    public function latestApplication(): HasMany
    {
        return $this->hasMany(ArtistApplication::class)->latestOfMany();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeUnderReview(Builder $query): Builder
    {
        return $query->where('status', 'under_review');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
