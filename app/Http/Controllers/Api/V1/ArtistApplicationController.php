<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Artist\ReviewApplication;
use App\Domain\Artist\SubmitApplication;
use App\Http\Controllers\Controller;
use App\Models\ArtistApplication;
use App\Models\ArtistProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Artist onboarding — application submit and admin review.
 */
final class ArtistApplicationController extends Controller
{
    /**
     * GET /api/v1/artist-applications
     *
     * Admin/operator: all applications paginated, filterable by status.
     * Artist: their own applications via profile.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (in_array($user->role, ['admin', 'operator'], true)) {
            $query = ArtistApplication::with(['artistProfile:id,user_id,display_name,slug,status'])
                ->orderByDesc('created_at');

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            return response()->json($query->paginate(25));
        }

        // Regular user — only their own profile's applications
        $profile = ArtistProfile::where('user_id', $user->id)->first();
        if (! $profile) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        return response()->json(
            $profile->applications()->orderByDesc('version')->paginate(10)
        );
    }

    /**
     * POST /api/v1/artist-applications
     *
     * Submit (or resubmit) an artist application.
     * Requires the artist to have an ArtistProfile already.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'portfolio_url'   => ['nullable', 'url', 'max:500'],
            'motivation'      => ['nullable', 'string', 'max:5000'],
            'id_document_path' => ['nullable', 'string', 'max:500'],
        ]);

        $user    = $request->user();
        $profile = ArtistProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            abort(422, 'Artist profile not found. Create a profile before submitting an application.');
        }

        if ($profile->status === 'approved') {
            abort(422, 'Your application has already been approved.');
        }

        $application = (new SubmitApplication())->execute(
            profile:        $profile,
            portfolioUrl:   $data['portfolio_url'] ?? null,
            motivation:     $data['motivation'] ?? null,
            idDocumentPath: $data['id_document_path'] ?? null,
        );

        return response()->json($application, 201);
    }

    /**
     * GET /api/v1/artist-applications/{application}
     *
     * Admin/operator: any. Artist: own only.
     */
    public function show(Request $request, ArtistApplication $application): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['admin', 'operator'], true)) {
            $profile = ArtistProfile::where('user_id', $user->id)->first();
            if (! $profile || $application->artist_profile_id !== $profile->id) {
                abort(403);
            }
        }

        $application->load('artistProfile:id,user_id,display_name,slug,status');

        return response()->json($application);
    }

    /**
     * POST /api/v1/artist-applications/{application}/approve
     *
     * Admin/operator: approve a submitted/under_review application.
     */
    public function approve(Request $request, ArtistApplication $application): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            (new ReviewApplication())->approve($application, $request->user(), $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($application->fresh()->load('artistProfile'));
    }

    /**
     * POST /api/v1/artist-applications/{application}/reject
     *
     * Admin/operator: reject a submitted/under_review application.
     */
    public function reject(Request $request, ArtistApplication $application): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        try {
            (new ReviewApplication())->reject($application, $request->user(), $data['note']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($application->fresh()->load('artistProfile'));
    }
}
