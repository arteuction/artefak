<?php

declare(strict_types=1);

namespace App\Domain\Artmetro;

use App\Models\ArtLot;
use App\Models\ArtmetroArtifact;

/**
 * ArtMetro bridge — attach an active ArtLot to a physical artifact label.
 *
 * When a gallery visitor scans the QR code the artifact's API response
 * can now surface live sale data (bid status, next bid, time remaining)
 * by following art_lot_id → ArtLot → AuctionItem.
 *
 * Rules:
 *   - The lot must be active (not closed/cancelled).
 *   - One artifact can only point at one lot at a time; passing null clears it.
 */
final class LinkArtLotToArtifact
{
    public function execute(ArtmetroArtifact $artifact, ?ArtLot $lot): ArtmetroArtifact
    {
        if ($lot !== null && ! in_array($lot->status, ['active', 'open'], true)) {
            throw new \DomainException(
                "ArtLot #{$lot->id} must be active or open to link to an artifact (status: {$lot->status})."
            );
        }

        $artifact->update(['art_lot_id' => $lot?->id]);

        return $artifact->refresh();
    }
}
