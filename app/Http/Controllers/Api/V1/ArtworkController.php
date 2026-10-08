<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArtworkController extends Controller
{
    /** GET /api/v1/artworks */
    public function index(Request $request): JsonResponse
    {
        $query = Artwork::with('artist:id,name')
            ->whereNotIn('status', ['draft', 'archived']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('artist_id')) {
            $query->where('user_id', (int) $request->input('artist_id'));
        }

        $artworks = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($artworks);
    }

    /** GET /api/v1/artworks/{artwork} */
    public function show(Artwork $artwork): JsonResponse
    {
        $artwork->load([
            'artist:id,name',
            'artLots' => fn ($q) => $q->whereIn('status', ['active', 'sold']),
            'revisions' => fn ($q) => $q->where('status', 'active'),
            'approvedSdgClaims',
        ]);

        return response()->json($artwork);
    }

    /** POST /api/v1/artworks */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'          => ['required', 'string', 'max:255'],
            'slug'           => ['required', 'string', 'max:255', 'unique:artworks,slug'],
            'medium'         => ['nullable', 'in:painting,sculpture,photography,digital,nft,mixed,other'],
            'dimensions'     => ['nullable', 'string', 'max:200'],
            'year_created'   => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'description'    => ['nullable', 'string'],
            'is_original'    => ['boolean'],
            'edition_number' => ['nullable', 'integer', 'min:1'],
            'edition_total'  => ['nullable', 'integer', 'min:1'],
        ]);

        $artwork = Artwork::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status'  => 'draft',
        ]);

        return response()->json($artwork, 201);
    }
}
