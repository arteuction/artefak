<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Rights\RightsData;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Rights
 */
final class RightsController extends Controller
{
    /**
     * Get rights declaration for an artwork.
     *
     * @unauthenticated
     * @response RightsData
     */
    public function show(Artwork $artwork): JsonResponse
    {
        return response()->json(RightsData::fromArtwork($artwork));
    }

    /**
     * Update rights declaration for an artwork.
     *
     * Only the artwork's artist or an admin may update rights.
     *
     * @response RightsData
     */
    public function update(Request $request, Artwork $artwork): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $artwork->user_id && $user->role !== 'admin') {
            abort(403, 'Only the artist or an admin may update rights.');
        }

        $data = $request->validate([
            // SPDX identifiers use hyphens, not spaces: "CC-BY-NC-4.0" not "CC BY-NC 4.0".
            // Pattern: identifier token optionally followed by WITH exception-token.
            'license_spdx'       => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-+]*(\s+WITH\s+[A-Za-z0-9][A-Za-z0-9.\-+]*)?$/'],
            'resale_royalty_bps' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'rights_statement'   => ['nullable', 'string', 'max:512'],
        ]);

        $artwork->update(array_filter($data, fn ($v) => $v !== null) + array_fill_keys(
            array_keys(array_filter($data, fn ($v) => $v === null)),
            null,
        ));

        return response()->json(RightsData::fromArtwork($artwork->fresh()));
    }
}
