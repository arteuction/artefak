<?php

declare(strict_types=1);

namespace App\Domain\Collection;

use App\Models\Collection;
use App\Models\User;
use InvalidArgumentException;

/**
 * Creates a new curated collection for a user.
 *
 * A Collection is a logical grouping of artworks — NOT an ownership record.
 * Provenance lives in OwnershipTransfer; Collection is a presentation layer.
 */
final class CreateCollection
{
    public function execute(
        User   $owner,
        string $title,
        string $slug,
        string $visibility  = 'private',
        string $description = '',
    ): Collection {
        if (! in_array($visibility, Collection::VISIBILITIES, true)) {
            throw new InvalidArgumentException(
                "Unknown visibility '{$visibility}'. Valid: " . implode(', ', Collection::VISIBILITIES)
            );
        }

        return Collection::create([
            'owner_id'    => $owner->id,
            'title'       => $title,
            'slug'        => $slug,
            'visibility'  => $visibility,
            'description' => $description ?: null,
        ]);
    }
}
