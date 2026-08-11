<?php

declare(strict_types=1);

namespace App\Domain\Artmetro;

use App\Models\ArtmetroArtifact;
use App\Models\ArtmetroArtifactScan;

/**
 * Records a QR-code scan event and returns the resolved artifact.
 *
 * Stateless — no Stripe, no locks needed. Safe to call from both
 * web (redirect flow) and API (JSON response).
 */
final class RecordArtifactScan
{
    public function execute(
        string  $qrToken,
        ?int    $userId      = null,
        ?string $referrer    = null,
        ?string $campaign    = null,
        ?string $locale      = null,
        ?string $ipAddress   = null,
        ?string $userAgent   = null,
    ): ArtmetroArtifact {
        $artifact = ArtmetroArtifact::where('qr_token', $qrToken)
            ->where('is_active', true)
            ->firstOrFail();

        ArtmetroArtifactScan::create([
            'artifact_id' => $artifact->id,
            'user_id'     => $userId,
            'referrer'    => $referrer    ? mb_substr($referrer,   0, 500) : null,
            'campaign'    => $campaign    ? mb_substr($campaign,   0, 120) : null,
            'locale'      => $locale      ? mb_substr($locale,     0,  10) : null,
            'ip_address'  => $ipAddress   ? mb_substr($ipAddress,  0,  45) : null,
            'user_agent'  => $userAgent   ? mb_substr($userAgent,  0, 500) : null,
            'scanned_at'  => now(),
        ]);

        return $artifact;
    }
}
