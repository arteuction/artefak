<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Collection\AddArtworkToCollection;
use App\Domain\Collection\CreateCollection;
use App\Domain\Collection\RemoveArtworkFromCollection;
use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectionController extends Controller
{
    /** GET /api/v1/collections */
    public function index(Request $request): JsonResponse
    {
        $collections = Collection::where('owner_id', $request->user()->id)
            ->withCount('artworks')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($collections);
    }

    /** GET /api/v1/collections/{collection} */
    public function show(Request $request, Collection $collection): JsonResponse
    {
        if ($collection->visibility === 'private' && $collection->owner_id !== $request->user()?->id) {
            abort(403);
        }

        $collection->load(['artworks:id,title,slug', 'owner:id,name']);

        return response()->json($collection);
    }

    /** POST /api/v1/collections */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'      => ['required', 'string', 'max:200'],
            'visibility' => ['nullable', 'in:private,unlisted,public'],
        ]);

        $collection = (new CreateCollection())->execute(
            owner:      $request->user(),
            title:      $data['title'],
            visibility: $data['visibility'] ?? 'private',
        );

        return response()->json($collection, 201);
    }

    /** POST /api/v1/collections/{collection}/artworks */
    public function addArtwork(Request $request, Collection $collection): JsonResponse
    {
        if ($collection->owner_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'artwork_id' => ['required', 'integer', 'exists:artworks,id'],
            'note'       => ['nullable', 'string', 'max:500'],
        ]);

        $artwork = Artwork::findOrFail((int) $data['artwork_id']);

        (new AddArtworkToCollection())->execute(
            collection: $collection,
            artwork:    $artwork,
            owner:      $request->user(),
            note:       $data['note'] ?? null,
        );

        return response()->json(['added' => true]);
    }

    /** DELETE /api/v1/collections/{collection}/artworks/{artwork} */
    public function removeArtwork(Request $request, Collection $collection, Artwork $artwork): JsonResponse
    {
        if ($collection->owner_id !== $request->user()->id) {
            abort(403);
        }

        (new RemoveArtworkFromCollection())->execute($collection, $artwork, $request->user());

        return response()->json(null, 204);
    }

    /** PATCH /api/v1/collections/{collection} */
    public function update(Request $request, Collection $collection): JsonResponse
    {
        if ($collection->owner_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility'  => ['sometimes', 'in:private,unlisted,public'],
        ]);

        $collection->update($data);

        return response()->json($collection->fresh());
    }

    /** DELETE /api/v1/collections/{collection} */
    public function destroy(Request $request, Collection $collection): JsonResponse
    {
        if ($collection->owner_id !== $request->user()->id) {
            abort(403);
        }

        $collection->delete();

        return response()->json(null, 204);
    }
}
