<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Asset\ActivateArtworkRevision;
use App\Domain\Asset\CreateArtworkRevision;
use App\Models\ArtworkEvidence;
use App\Models\ArtworkRevision;
use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

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

    /** POST /api/v1/artworks/{artwork}/revisions */
    public function storeRevision(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'title'             => ['required', 'string', 'max:255'],
            'reason'            => ['required', 'in:initial,correction,restoration_documented,attribution_updated,provenance_expanded,certificate_added'],
            'year_created'      => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'medium'            => ['nullable', 'string', 'max:200'],
            'dimensions_notes'  => ['nullable', 'string', 'max:200'],
            'description'       => ['nullable', 'string'],
            'edition_info'      => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $revision = (new CreateArtworkRevision())->execute(
                artwork:          $artwork,
                revisedBy:        $request->user(),
                title:            $data['title'],
                reason:           $data['reason'],
                yearCreated:      isset($data['year_created']) ? (int) $data['year_created'] : null,
                medium:           $data['medium'] ?? null,
                dimensionsNotes:  $data['dimensions_notes'] ?? null,
                description:      $data['description'] ?? null,
                editionInfo:      $data['edition_info'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($revision, 201);
    }

    /** POST /api/v1/artworks/{artwork}/revisions/{revision}/activate */
    public function activateRevision(Request $request, Artwork $artwork, ArtworkRevision $revision): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($revision->artwork_id !== $artwork->id) {
            abort(404);
        }

        try {
            $revision = (new ActivateArtworkRevision())->execute($revision);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($revision);
    }

    /**
     * GET /api/v1/artworks/{artwork}/evidence
     *
     * Public list of verified evidence for an artwork.
     * Admin sees all statuses; public sees only verified.
     */
    public function indexEvidence(Request $request, Artwork $artwork): JsonResponse
    {
        $query = ArtworkEvidence::where('artwork_id', $artwork->id);

        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'operator'], true)) {
            $query->where('verification_status', 'verified');
        }

        return response()->json(['data' => $query->orderByDesc('issued_at')->get()]);
    }

    /**
     * POST /api/v1/artworks/{artwork}/evidence
     *
     * Add an evidence record to an artwork.
     * Owner or admin/operator only.
     */
    public function storeEvidence(Request $request, Artwork $artwork): JsonResponse
    {
        $user = $request->user();
        $isOwner = $artwork->user_id === $user->id;
        $isAdmin = in_array($user->role, ['admin', 'operator'], true);

        if (! $isOwner && ! $isAdmin) {
            abort(403);
        }

        $data = $request->validate([
            'type'          => ['required', 'in:authenticity,provenance,condition,ownership,certificate,exhibition_history,restoration'],
            'issuer'        => ['nullable', 'string', 'max:200'],
            'issued_at'     => ['nullable', 'date'],
            'document_path' => ['nullable', 'string', 'max:500'],
            'notes'         => ['nullable', 'string', 'max:2000'],
        ]);

        $evidence = ArtworkEvidence::create([
            ...$data,
            'artwork_id'          => $artwork->id,
            'verification_status' => 'pending',
        ]);

        return response()->json($evidence, 201);
    }
}
