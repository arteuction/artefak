<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\ArtworkRevision;
use Illuminate\Support\Facades\DB;

/**
 * Activate an artwork revision — makes it 'active' and supersedes any previous active revision.
 *
 * Only one revision per artwork can be 'active' at a time.
 * Previous active revision transitions to 'superseded'.
 */
final class ActivateArtworkRevision
{
    public function execute(ArtworkRevision $revision): ArtworkRevision
    {
        if ($revision->status === 'active') {
            return $revision;
        }

        if ($revision->status !== 'draft') {
            throw new \DomainException(
                "Cannot activate revision #{$revision->id} in status '{$revision->status}'."
            );
        }

        DB::transaction(function () use ($revision): void {
            // Supersede any currently-active revision for this artwork
            ArtworkRevision::where('artwork_id', $revision->artwork_id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            $revision->update([
                'status'         => 'active',
                'effective_from' => now(),
            ]);
        });

        return $revision->refresh();
    }
}
