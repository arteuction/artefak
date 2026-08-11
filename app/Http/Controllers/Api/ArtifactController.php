<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Artmetro\RecordArtifactScan;
use App\Domain\Artmetro\RecordVisit;
use App\Http\Controllers\Controller;
use App\Models\ArtmetroArtifact;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtifactController extends Controller
{
    public function __construct(
        private readonly RecordArtifactScan $recordScan,
        private readonly RecordVisit        $recordVisit,
    ) {}

    /**
     * GET /api/artifacts/{qrToken}
     *
     * Resolves a QR token, records the scan, returns artifact data.
     */
    public function scan(Request $request, string $qrToken): JsonResponse
    {
        try {
            $artifact = $this->recordScan->execute(
                qrToken:   $qrToken,
                userId:    $request->user()?->id,
                referrer:  $request->input('referrer') ?? $request->header('Referer'),
                campaign:  $request->input('campaign'),
                locale:    $request->input('locale') ?? $request->getPreferredLanguage(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Artifact not found.'], 404);
        }

        return response()->json([
            'artifact' => [
                'id'           => $artifact->id,
                'title'        => $artifact->title,
                'description'  => $artifact->description,
                'ar_model_url' => $artifact->ar_model_url,
                'exhibition'   => [
                    'id'    => $artifact->exhibition_id,
                    'title' => $artifact->exhibition?->title,
                ],
            ],
        ]);
    }

    /**
     * POST /api/artifacts/{artifact}/visit
     *
     * Beacon endpoint — called by JS when visitor leaves the artifact page.
     */
    public function visit(Request $request, ArtmetroArtifact $artifact): JsonResponse
    {
        $data = $request->validate([
            'session_id'           => ['nullable', 'string', 'max:64'],
            'time_on_page_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'video_watched'        => ['nullable', 'boolean'],
            'ar_launched'          => ['nullable', 'boolean'],
            'bid_clicked'          => ['nullable', 'boolean'],
        ]);

        $this->recordVisit->execute(
            artifact:          $artifact,
            userId:            $request->user()?->id,
            sessionId:         $data['session_id'] ?? null,
            timeOnPageSeconds: (int) ($data['time_on_page_seconds'] ?? 0),
            videoWatched:      (bool) ($data['video_watched'] ?? false),
            arLaunched:        (bool) ($data['ar_launched'] ?? false),
            bidClicked:        (bool) ($data['bid_clicked'] ?? false),
        );

        return response()->json(['ok' => true], 202);
    }
}
