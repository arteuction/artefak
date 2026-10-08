<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\Artwork;
use App\Models\ArtworkRevision;
use App\Models\User;
use InvalidArgumentException;

final class CreateArtworkRevision
{
    public function execute(
        Artwork $artwork,
        User    $revisedBy,
        string  $title,
        string  $reason = 'initial',
        ?int    $yearCreated      = null,
        ?string $medium           = null,
        ?string $dimensionsNotes  = null,
        ?string $description      = null,
        ?string $editionInfo      = null,
    ): ArtworkRevision {
        $validReasons = [
            'initial', 'correction', 'restoration_documented',
            'attribution_updated', 'provenance_expanded', 'certificate_added',
        ];

        if (! in_array($reason, $validReasons, true)) {
            throw new InvalidArgumentException("Invalid revision reason: {$reason}");
        }

        // Next version number for this artwork
        $nextVersion = ArtworkRevision::where('artwork_id', $artwork->id)->max('version') + 1;

        return ArtworkRevision::create([
            'artwork_id'       => $artwork->id,
            'version'          => $nextVersion,
            'title'            => $title,
            'year_created'     => $yearCreated,
            'medium'           => $medium,
            'dimensions_notes' => $dimensionsNotes,
            'description'      => $description,
            'edition_info'     => $editionInfo,
            'revised_by'       => $revisedBy->id,
            'reason'           => $reason,
            'status'           => 'draft',
            'effective_from'   => null,
        ]);
    }
}
