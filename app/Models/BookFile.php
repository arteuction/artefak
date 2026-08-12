<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BookFile extends Model
{
    // Immutable — no updated_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'book_id', 'uploaded_by', 'version',
        'disk', 'path', 'filename', 'mime', 'size_bytes', 'sha256',
        'type', 'status',
    ];

    protected function casts(): array
    {
        return [
            'version'    => 'integer',
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
