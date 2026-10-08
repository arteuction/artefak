<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Artmetro\TagExhibitionSdgs;
use App\Http\Controllers\Controller;
use App\Models\Exhibition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ExhibitionController extends Controller
{
    /**
     * POST /api/v1/exhibitions
     *
     * Gallery staff (for that venue's gallery) or admin/operator creates an exhibition.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'venue_id'    => ['required', 'integer', 'exists:venues,id'],
            'starts_at'   => ['required', 'date'],
            'ends_at'     => ['required', 'date', 'after:starts_at'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $galleryIds = \App\Models\Gallery::where('venue_id', $data['venue_id'])->pluck('id');
        $isGalleryStaff = $galleryIds->isNotEmpty()
            && \App\Models\GalleryStaff::whereIn('gallery_id', $galleryIds)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

        if (! $isGalleryStaff && ! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403, 'Gallery staff or admin required.');
        }

        $exhibition = Exhibition::create([
            ...$data,
            'slug'      => Str::slug($data['title']) . '-' . time(),
            'is_active' => $data['is_active'] ?? false,
        ]);

        return response()->json($exhibition, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Exhibition::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('gallery_id')) {
            $query->where('gallery_id', $request->integer('gallery_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('starts_at')->paginate(25),
        ]);
    }

    public function show(Exhibition $exhibition): JsonResponse
    {
        return response()->json(['data' => $exhibition]);
    }

    /**
     * PUT /api/v1/exhibitions/{exhibition}/sdg-tags
     *
     * Replace the SDG tags on an exhibition atomically.
     * Gallery staff (of this exhibition's gallery) or admin/operator.
     * Pass an empty array to clear all tags.
     */
    public function tagSdgs(Request $request, Exhibition $exhibition): JsonResponse
    {
        $data = $request->validate([
            'sdg_numbers'   => ['required', 'array'],
            'sdg_numbers.*' => ['integer', 'min:1', 'max:17'],
        ]);

        $user = $request->user();
        // Exhibition → venue → gallery (gallery.venue_id matches exhibition.venue_id)
        $galleryIds = \App\Models\Gallery::where('venue_id', $exhibition->venue_id)->pluck('id');
        $isGalleryStaff = $galleryIds->isNotEmpty()
            && \App\Models\GalleryStaff::whereIn('gallery_id', $galleryIds)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

        if (! $isGalleryStaff && ! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403, 'Gallery staff or admin required.');
        }

        (new TagExhibitionSdgs())->execute($exhibition, $data['sdg_numbers']);

        $tags = \Illuminate\Support\Facades\DB::table('artmetro_exhibition_sdg')
            ->where('exhibition_id', $exhibition->id)
            ->pluck('sdg_number');

        return response()->json(['data' => $tags]);
    }
}
