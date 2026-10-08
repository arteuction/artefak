<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Collection extends Model
{
    public const VISIBILITIES = ['private', 'unlisted', 'public'];

    protected $fillable = [
        'owner_id',
        'title',
        'slug',
        'description',
        'cover_image_url',
        'visibility',
    ];

    protected $attributes = [
        'visibility' => 'private',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function artworks(): BelongsToMany
    {
        return $this->belongsToMany(Artwork::class, 'collection_artworks')
            ->withPivot(['position', 'note'])
            ->orderByPivot('position')
            ->withTimestamps();
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }
}
