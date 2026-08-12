<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BookAuthor extends Model
{
    protected $fillable = ['book_id', 'author_id', 'share_bps', 'sort_order'];

    protected function casts(): array
    {
        return [
            'share_bps'  => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
