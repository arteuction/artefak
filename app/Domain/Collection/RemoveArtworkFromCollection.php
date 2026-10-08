<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;

/** Removes an artwork from a collection. Owner-only. Idempotent. */
final class RemoveArtworkFromCollection
{
    public function execute(Collection $collection, Artwork $artwork, User $by): void
    {
        if (! $collection->isOwnedBy($by)) {
            throw new \DomainException(
                "User #{$by->id} does not own collection #{$collection->id}."
            );
        }

        $collection->artworks()->detach($artwork->id);
    }
}
