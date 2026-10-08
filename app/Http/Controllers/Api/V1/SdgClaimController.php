<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SdgClaimController extends Controller
{
    /**
     * GET /api/v1/artworks/{artwork}/sdg-claims
     * Public: approved claims. Authenticated owner/admin: all claims.
     */
    public function index(Request $request, Artwork $artwork): JsonResponse
    {
        $query = $artwork->sdgClaims()->with('reviewer:id,name');

        $user = $request->user();
        $isOwnerOrAdmin = $user && ($artwork->user_id === $user->id || in_array($user->role, ['admin', 'operator'], true));

        if (! $isOwnerOrAdmin) {
            $query->where('status', 'approved');
        }

        return response()->json($query->orderBy('sdg_number')->get());
    }

    /**
     * POST /api/v1/artworks/{artwork}/sdg-claims
     * Artwork owner submits or updates a claim for a given SDG number.
     * Unique constraint on (artwork_id, sdg_number) — duplicate submission updates existing.
     */
    public function store(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'sdg_number' => ['required', 'integer', 'min:1', 'max:17'],
            'rationale'  => ['required', 'string', 'max:5000'],
            'evidence'   => ['nullable', 'string', 'max:5000'],
        ]);

        $claim = ArtworkSdgClaim::updateOrCreate(
            ['artwork_id' => $artwork->id, 'sdg_number' => (int) $data['sdg_number']],
            [
                'rationale'   => $data['rationale'],
                'evidence'    => $data['evidence'] ?? null,
                'status'      => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ],
        );

        return response()->json($claim, $claim->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/v1/artworks/{artwork}/sdg-claims/{claim}/review
     * Admin/operator approves or rejects a pending claim.
     */
    public function review(Request $request, Artwork $artwork, ArtworkSdgClaim $claim): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        if ($claim->artwork_id !== $artwork->id) {
            abort(404);
        }

        if ($claim->status !== 'pending') {
            abort(422, "Claim is already '{$claim->status}'.");
        }

        $data = $request->validate([
            'decision'    => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $claim->update([
            'status'      => $data['decision'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        return response()->json($claim->fresh());
    }
}
