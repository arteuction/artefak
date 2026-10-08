<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ArtworkRevision extends Model
{
    protected $fillable = [
        'artwork_id',
        'version',
        'title',
        'year_created',
        'medium',
        'dimensions_notes',
        'description',
        'edition_info',
        'revised_by',
        'reason',
        'status',
        'effective_from',
    ];

    protected $casts = [
        'version'        => 'integer',
        'year_created'   => 'integer',
        'effective_from' => 'datetime',
    ];

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    public function artLots(): HasMany
    {
        return $this->hasMany(ArtLot::class, 'artwork_revision_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }
}
