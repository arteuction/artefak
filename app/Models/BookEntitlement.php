<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BookEntitlement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'book_id', 'book_purchase_id',
        'source', 'file_version', 'granted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at'   => 'datetime',
            'revoked_at'   => 'datetime',
            'file_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(BookPurchase::class, 'book_purchase_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
