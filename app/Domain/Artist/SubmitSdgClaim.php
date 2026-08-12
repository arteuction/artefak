<?php

declare(strict_types=1);

namespace App\Domain\Artist;

use App\Models\AdminAuditLog;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use InvalidArgumentException;

final class SubmitSdgClaim
{
    public function execute(
        Artwork $artwork,
        int     $sdgNumber,
        string  $rationale,
        ?string $evidence  = null,
    ): ArtworkSdgClaim {
        if ($sdgNumber < 1 || $sdgNumber > 17) {
            throw new InvalidArgumentException("SDG number must be between 1 and 17, got [{$sdgNumber}].");
        }

        // Upsert: re-submission resets to pending and updates the claim
        $claim = ArtworkSdgClaim::updateOrCreate(
            ['artwork_id' => $artwork->id, 'sdg_number' => $sdgNumber],
            [
                'rationale'   => $rationale,
                'evidence'    => $evidence,
                'status'      => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ],
        );

        AdminAuditLog::record($artwork->user_id, $claim, 'sdg_claim.submitted', [
            'sdg_number' => $sdgNumber,
        ]);

        return $claim;
    }
}
