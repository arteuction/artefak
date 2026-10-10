<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Asset\TransitionArtLot;
use App\Domain\Provenance\BuildProvenanceGraph;
use App\Domain\SellNow\PurchaseAtFixedPrice;
use App\Http\Controllers\Controller;
use App\Models\ArtLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ArtLotController extends Controller
{
    /** GET /api/v1/art-lots */
    public function index(Request $request): JsonResponse
    {
        $query = ArtLot::with(['artwork:id,title,slug', 'gallery:id,name,slug'])
            ->whereIn('status', ['active', 'catalogued', 'scheduled']);

        if ($request->filled('sale_mode')) {
            $query->where('sale_mode', $request->input('sale_mode'));
        }

        if ($request->filled('gallery_id')) {
            $query->where('gallery_id', (int) $request->input('gallery_id'));
        }

        $lots = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($lots);
    }

    /** GET /api/v1/art-lots/{artLot} */
    public function show(ArtLot $artLot): JsonResponse
    {
        $artLot->load([
            'artwork.artist:id,name',
            'artwork.approvedSdgClaims',
            'gallery:id,name,slug',
            'consignment:id,owner_id,consignor_id,commission_bps,status',
            'artworkRevision',
        ]);

        return response()->json($artLot);
    }

    /** POST /api/v1/art-lots */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'artwork_id'           => ['required', 'integer', 'exists:artworks,id'],
            'sale_mode'            => ['required', 'in:auction,sell_now,hybrid'],
            'currency'             => ['required', 'string', 'size:3'],
            'reserve_price_cents'  => ['nullable', 'integer', 'min:0'],
            'starting_bid_cents'   => ['nullable', 'integer', 'min:0'],
            'buy_now_price_cents'  => ['nullable', 'integer', 'min:1'],
            'estimate_low_cents'   => ['nullable', 'integer', 'min:0'],
            'estimate_high_cents'  => ['nullable', 'integer', 'min:0'],
            'split_profile_key'    => ['nullable', 'string', 'max:80'],
            'gallery_id'           => ['nullable', 'integer', 'exists:galleries,id'],
            'consignment_id'       => ['nullable', 'integer', 'exists:consignments,id'],
        ]);

        $lot = ArtLot::create([
            ...$data,
            'consignor_id' => $request->user()->id,
            'status'       => 'draft',
        ]);

        return response()->json($lot->load('artwork:id,title,slug'), 201);
    }

    /** PATCH /api/v1/art-lots/{artLot} */
    public function update(Request $request, ArtLot $artLot): JsonResponse
    {
        $user    = $request->user();
        $isOwner = $artLot->consignor_id === $user->id;
        $isAdmin = in_array($user->role, ['admin', 'operator'], true);

        if (! $isOwner && ! $isAdmin) {
            abort(403);
        }

        if ($artLot->status !== 'draft') {
            abort(422, 'Only draft lots can be updated.');
        }

        $data = $request->validate([
            'reserve_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'starting_bid_cents'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'buy_now_price_cents' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'estimate_low_cents'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'estimate_high_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sale_mode'           => ['sometimes', 'in:auction,sell_now,hybrid'],
        ]);

        $artLot->update($data);

        return response()->json($artLot->fresh());
    }

    /**
     * GET /api/v1/art-lots/{artLot}/provenance
     *
     * W3C PROV-O JSON-LD provenance graph for this lot.
     * Includes the artwork entity, every domain event as a prov:Activity,
     * and all participating prov:Agent nodes.
     * Buyer/seller identity is included only as opaque agent IRIs.
     *
     * @unauthenticated
     * @response array{@context: array, @graph: array}
     */
    public function provenance(ArtLot $artLot, BuildProvenanceGraph $builder): JsonResponse
    {
        $graph = $builder->execute($artLot);

        return response()->json($graph)
            ->header('Content-Type', 'application/ld+json');
    }

    /**
     * GET /api/v1/art-lots/{artLot}/bids
     *
     * Public bid history — amounts and timestamps only; no bidder identity exposed.
     */
    public function bids(ArtLot $artLot): JsonResponse
    {
        $item = $artLot->auctionItem()->first();

        if ($item === null) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $bids = $item->bids()
            ->where('status', 'accepted')
            ->orderByDesc('amount_cents')
            ->get(['id', 'amount_cents', 'currency', 'created_at']);

        return response()->json([
            'data' => $bids,
            'meta' => ['total' => $bids->count()],
        ]);
    }

    /**
     * POST /api/v1/art-lots/{artLot}/transitions/{transition}
     *
     * Advance the lot through its lifecycle.
     * Allowed transitions: submit, verify, approve, catalogue, schedule, activate.
     * Authorization:
     *   submit   — consignor only
     *   verify, approve, catalogue, schedule, activate — gallery staff or admin
     */
    public function transition(Request $request, ArtLot $artLot, string $transition): JsonResponse
    {
        $user     = $request->user();
        $staffMap = ['verify', 'approve', 'catalogue', 'schedule', 'activate'];

        if ($transition === 'submit') {
            if ($artLot->consignor_id !== $user->id) {
                abort(403, 'Only the consignor can submit a lot.');
            }
        } elseif (in_array($transition, $staffMap, true)) {
            $isGalleryStaff = $artLot->gallery_id
                && \App\Models\GalleryStaff::where('gallery_id', $artLot->gallery_id)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->exists();

            if (! $isGalleryStaff && ! in_array($user->role, ['admin', 'operator'], true)) {
                abort(403, 'Gallery staff or admin required.');
            }
        } else {
            abort(422, "Unknown transition '{$transition}'.");
        }

        try {
            $artLot = (new TransitionArtLot())->execute($artLot, $transition);
        } catch (\DomainException|\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($artLot);
    }

    /**
     * POST /api/v1/art-lots/{artLot}/purchase-now
     *
     * Instant buy at the fixed buy_now_price_cents — valid for sell_now and hybrid lots.
     */
    public function purchaseNow(Request $request, ArtLot $artLot): JsonResponse
    {
        try {
            $offer = (new PurchaseAtFixedPrice())->execute(
                artLot:    $artLot,
                buyer:     $request->user(),
                galleryId: $artLot->gallery_id,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($offer, 201);
    }
}
