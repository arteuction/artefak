<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArtworkEvidence extends Model
{
    protected $fillable = [
        'artwork_id', 'type', 'issuer', 'issued_at',
        'document_path', 'verification_status', 'visibility', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
        ];
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }
}
