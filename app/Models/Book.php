<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Book extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'title', 'slug', 'description', 'short_description',
        'isbn', 'publisher', 'language', 'edition', 'page_count',
        'publication_year', 'country_origin',
        'price_cents', 'currency', 'is_free', 'preview_pages',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'is_featured', 'current_file_version',
    ];

    protected function casts(): array
    {
        return [
            'is_free'      => 'boolean',
            'is_featured'  => 'boolean',
            'reviewed_at'  => 'datetime',
            'price_cents'  => 'integer',
            'page_count'   => 'integer',
            'preview_pages' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bookAuthors(): HasMany
    {
        return $this->hasMany(BookAuthor::class)->orderBy('sort_order');
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'book_authors', 'book_id', 'author_id')
                    ->withPivot('share_bps', 'sort_order')
                    ->orderByPivot('sort_order');
    }

    public function files(): HasMany
    {
        return $this->hasMany(BookFile::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(BookPurchase::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(BookEntitlement::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function isPurchasable(): bool
    {
        return ! $this->is_free && $this->price_cents > 0 && $this->status === 'published';
    }
}
