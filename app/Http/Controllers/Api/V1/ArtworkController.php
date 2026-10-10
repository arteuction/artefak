<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\ArtworkData;
use App\Domain\Asset\ActivateArtworkRevision;
use App\Domain\Asset\ConfirmArtworkImageUpload;
use App\Domain\Asset\CreateArtworkRevision;
use App\Domain\Asset\GenerateArtworkDerivatives;
use App\Domain\Asset\PublishArtworkRevision;
use App\Domain\Asset\RequestArtworkImageUpload;
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
    public function show(Request $request, Artwork $artwork): JsonResponse
    {
        // Draft and archived artworks are only visible to their owner and admins
        if (in_array($artwork->status, ['draft', 'archived'], true)) {
            $user = $request->user();
            if ($user === null || ($artwork->user_id !== $user->id && ! in_array($user->role, ['admin', 'operator'], true))) {
                abort(403);
            }
        }

        $artwork->load(['artist:id,name']);

        return response()->json(ArtworkData::fromArtwork($artwork));
    }

    /** PATCH /api/v1/artworks/{artwork} */
    public function update(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id && ! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'title'          => ['sometimes', 'string', 'max:255'],
            'medium'         => ['sometimes', 'nullable', 'in:painting,sculpture,photography,digital,nft,mixed,other'],
            'dimensions'     => ['sometimes', 'nullable', 'string', 'max:200'],
            'year_created'   => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:2100'],
            'description'    => ['sometimes', 'nullable', 'string'],
            'is_original'    => ['sometimes', 'boolean'],
            'edition_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'edition_total'  => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $artwork->update($data);

        return response()->json($artwork->fresh());
    }

    /** POST /api/v1/artworks */
    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['artist', 'admin', 'operator'], true)) {
            abort(403);
        }

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
            $query->where('verification_status', 'verified')
                  ->where('visibility', 'public');
        }

        return response()->json(['data' => $query->orderByDesc('issued_at')->get()]);
    }

    /**
     * POST /api/v1/artworks/{artwork}/images/presign
     *
     * Issue a presigned S3 PUT URL for direct-to-S3 image upload.
     * Owner only. Returns {upload_url, key, expires_in_seconds, max_bytes}.
     */
    public function presignImage(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'extension' => ['nullable', 'in:jpg,jpeg,png,webp'],
        ]);

        try {
            $result = (new RequestArtworkImageUpload())->execute(
                $artwork,
                $data['extension'] ?? 'jpg',
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($result);
    }

    /**
     * POST /api/v1/artworks/{artwork}/images/confirm
     *
     * Confirm that a presigned upload completed successfully.
     * Verifies the object exists in S3, then sets status to 'confirmed'.
     * Owner only.
     */
    public function confirmImage(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id) {
            abort(403);
        }

        try {
            $artwork = (new ConfirmArtworkImageUpload())->execute($artwork);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'id'                   => $artwork->id,
            'primary_image_key'    => $artwork->primary_image_key,
            'primary_image_status' => $artwork->primary_image_status,
        ]);
    }

    /**
     * POST /api/v1/artworks/{artwork}/images/derivatives
     *
     * Trigger generation of thumb/medium/large WebP derivatives from the confirmed
     * primary image. Owner or admin only. Kicks off a synchronous or queued job
     * depending on image size; returns the derivative keys on success.
     */
    public function generateDerivatives(Request $request, Artwork $artwork): JsonResponse
    {
        $user = $request->user();
        if ($artwork->user_id !== $user->id && ! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        try {
            $keys = (new GenerateArtworkDerivatives())->execute($artwork);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['derivatives' => $keys]);
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

    /** GET /api/v1/my/artworks — authenticated artist's own artworks, all statuses */
    public function mine(Request $request): JsonResponse
    {
        $artworks = Artwork::with('artist:id,name')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($artworks);
    }

    /** POST /api/v1/artworks/{artwork}/submit — artist submits draft for review (sets status to listed) */
    public function submit(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($artwork->status !== 'draft') {
            return response()->json(['message' => 'Only draft artworks can be submitted.'], 422);
        }

        $artwork->update(['status' => 'listed']);

        return response()->json($artwork->fresh());
    }

    /** GET /api/v1/artworks/{artwork}/lots — ArtLots associated with this artwork */
    public function lots(Artwork $artwork): JsonResponse
    {
        $lots = \App\Models\ArtLot::where('artwork_id', $artwork->id)
            ->orderByDesc('created_at')
            ->get(['id', 'artwork_id', 'auction_id', 'status', 'reserve_price_cents',
                   'starting_bid_cents', 'current_bid_cents', 'bid_count', 'currency']);

        return response()->json(['data' => $lots]);
    }
}
