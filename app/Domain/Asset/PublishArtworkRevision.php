<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\ArtworkRevision;
use InvalidArgumentException;

final class PublishArtworkRevision
{
    public function execute(ArtworkRevision $revision): ArtworkRevision
    {
        if ($revision->status !== 'draft') {
            throw new InvalidArgumentException(
                "Cannot publish revision with status '{$revision->status}'."
            );
        }

        // Supersede the current active revision for this artwork
        ArtworkRevision::where('artwork_id', $revision->artwork_id)
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        $revision->update([
            'status'         => 'active',
            'effective_from' => now(),
        ]);

        return $revision->fresh();
    }
}
