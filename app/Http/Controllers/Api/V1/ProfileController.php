<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\UserProfileData;
use App\Http\Controllers\Controller;
use App\Models\ArtistProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ProfileController extends Controller
{
    /** GET /api/v1/me */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(UserProfileData::fromUser($user));
    }

    /**
     * POST /api/v1/artist-profile
     *
     * Creates the authenticated user's artist profile (idempotent: returns existing if already created).
     */
    public function createArtistProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = ArtistProfile::where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        $data = $request->validate([
            'display_name'     => ['required', 'string', 'max:200'],
            'bio'              => ['nullable', 'string', 'max:5000'],
            'website'          => ['nullable', 'url', 'max:500'],
            'instagram_handle' => ['nullable', 'string', 'max:100'],
            'terms_version'    => ['nullable', 'string', 'max:20'],
        ]);

        $profile = ArtistProfile::create([
            'user_id'           => $user->id,
            'display_name'      => $data['display_name'],
            'slug'              => Str::slug($data['display_name']) . '-' . $user->id,
            'bio'               => $data['bio'] ?? null,
            'website'           => $data['website'] ?? null,
            'instagram_handle'  => $data['instagram_handle'] ?? null,
            'status'            => 'pending',
            'terms_accepted_at' => $data['terms_version'] ? now() : null,
            'terms_version'     => $data['terms_version'] ?? null,
        ]);

        return response()->json($profile, 201);
    }

    /**
     * GET /api/v1/artist-profile
     *
     * Returns the authenticated user's artist profile (or 404).
     */
    public function showArtistProfile(Request $request): JsonResponse
    {
        $profile = ArtistProfile::where('user_id', $request->user()->id)
            ->with('applications')
            ->first();

        if (! $profile) {
            abort(404, 'Artist profile not found.');
        }

        return response()->json($profile);
    }

    /**
     * PATCH /api/v1/artist-profile
     *
     * Updates the authenticated user's artist profile.
     */
    public function updateArtistProfile(Request $request): JsonResponse
    {
        $profile = ArtistProfile::where('user_id', $request->user()->id)->first();

        if (! $profile) {
            abort(404, 'Artist profile not found.');
        }

        $data = $request->validate([
            'display_name'     => ['sometimes', 'string', 'max:200'],
            'bio'              => ['nullable', 'string', 'max:5000'],
            'website'          => ['nullable', 'url', 'max:500'],
            'instagram_handle' => ['nullable', 'string', 'max:100'],
        ]);

        $profile->update($data);

        return response()->json($profile->fresh());
    }
}
