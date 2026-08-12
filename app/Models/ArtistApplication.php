<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArtistApplication extends Model
{
    protected $fillable = [
        'artist_profile_id', 'status',
        'id_document_path', 'portfolio_url', 'motivation',
        'reviewed_by', 'reviewed_at', 'review_note',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'version'     => 'integer',
        ];
    }

    public function artistProfile(): BelongsTo
    {
        return $this->belongsTo(ArtistProfile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    public function scopeUnderReview(Builder $query): Builder
    {
        return $query->where('status', 'under_review');
    }
}
