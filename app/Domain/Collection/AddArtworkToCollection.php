<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;

/**
 * Adds an artwork to a collection.
 *
 * Rules:
 *   - Only the collection owner may add artworks.
 *   - The same artwork cannot appear twice (pivot unique constraint guards this;
 *     we check first for a friendly error).
 *   - Caller may supply a curator note and display position.
 *   - Does NOT check artwork ownership: a collector may curate a wish-list
 *     or thematic editorial collection with artworks they do not own.
 */
final class AddArtworkToCollection
{
    public function execute(
        Collection $collection,
        Artwork    $artwork,
        User       $by,
        int        $position = 0,
        string     $note     = '',
    ): void {
        if (! $collection->isOwnedBy($by)) {
            throw new \DomainException(
                "User #{$by->id} does not own collection #{$collection->id}."
            );
        }

        if ($collection->artworks()->where('artwork_id', $artwork->id)->exists()) {
            return; // idempotent
        }

        $collection->artworks()->attach($artwork->id, [
            'position' => $position,
            'note'     => $note ?: null,
        ]);
    }
}
