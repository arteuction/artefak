<?php

declare(strict_types=1);

namespace App\Domain\Artmetro;

use App\Models\ArtmetroArtifact;
use App\Models\ArtmetroVisit;

/**
 * Records a visitor's engagement depth for an artifact.
 *
 * Called client-side (JS beacon) after the user leaves the artifact page.
 * All fields are optional — even a zero-second visit is recorded.
 */
final class RecordVisit
{
    public function execute(
        ArtmetroArtifact $artifact,
        ?int    $userId            = null,
        ?string $sessionId         = null,
        int     $timeOnPageSeconds = 0,
        bool    $videoWatched      = false,
        bool    $arLaunched        = false,
        bool    $bidClicked        = false,
    ): ArtmetroVisit {
        return ArtmetroVisit::create([
            'artifact_id'          => $artifact->id,
            'user_id'              => $userId,
            'session_id'           => $sessionId ? mb_substr($sessionId, 0, 64) : null,
            'time_on_page_seconds' => max(0, $timeOnPageSeconds),
            'video_watched'        => $videoWatched,
            'ar_launched'          => $arLaunched,
            'bid_clicked'          => $bidClicked,
            'visited_at'           => now(),
        ]);
    }
}
