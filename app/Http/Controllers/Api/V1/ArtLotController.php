<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArtLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
