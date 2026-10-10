<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\DataPortability\ExportUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Data Portability
 */
final class DataPortabilityController extends Controller
{
    public function __construct(private ExportUserData $exporter) {}

    /**
     * Export all personal data for the authenticated user (GDPR Art. 20).
     *
     * Returns a JSON document containing profile data, artworks, offers,
     * bids, donations, ledger entries, impact events, and watchlist.
     * The response is set to trigger a browser download.
     *
     * @response array{exported_at: string, schema_version: string, subject: array}
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $this->exporter->execute($user);

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="arteuction-export.json"');
    }
}
