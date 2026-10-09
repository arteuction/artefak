<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public artwork catalog — searchable, filterable, sortable.
 * Only 'listed' artworks are exposed.
 */
final class PublicArtworkController extends Controller
{
    private const PAGE_SIZE = 20;

    /**
     * GET /api/v1/artworks
     *
     * Query params:
     *   q        — full-text search on title + description
     *   medium   — one of painting|sculpture|photography|digital|nft|mixed|other
     *   sdg      — integer 1–17 (UN SDG number)
     *   min_price — price floor in cents (against art_lots.buy_now_price_cents or starting_bid_cents)
     *   max_price — price ceiling in cents
     *   sort     — newest (default) | price_asc | price_desc
     *   page     — pagination page (default 1)
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'         => ['nullable', 'string', 'max:200'],
            'medium'    => ['nullable', 'in:painting,sculpture,photography,digital,nft,mixed,other'],
            'sdg'       => ['nullable', 'integer', 'min:1', 'max:17'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'sort'      => ['nullable', 'in:newest,price_asc,price_desc'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Artwork::query()
            ->where('artworks.status', 'listed')
            ->select('artworks.*')
            ->with(['artist:id,name']);

        // Full-text search via Scout (Meilisearch in production, collection driver in test/dev)
        if (! empty($validated['q'])) {
            $matchIds = Artwork::search($validated['q'])->keys();
            $query->whereIn('artworks.id', $matchIds->isEmpty() ? [0] : $matchIds);
        }

        // Medium filter
        if (! empty($validated['medium'])) {
            $query->where('artworks.medium', $validated['medium']);
        }

        // SDG filter — join artwork_sdg_claims
        if (! empty($validated['sdg'])) {
            $query->whereExists(function ($sub) use ($validated): void {
                $sub->select(DB::raw(1))
                    ->from('artwork_sdg_claims')
                    ->whereColumn('artwork_sdg_claims.artwork_id', 'artworks.id')
                    ->where('artwork_sdg_claims.sdg_number', (int) $validated['sdg']);
            });
        }

        // Price filter — join art_lots for price columns
        $needsLotJoin = isset($validated['min_price']) || isset($validated['max_price']) || ($validated['sort'] ?? 'newest') !== 'newest';

        if ($needsLotJoin) {
            $query->leftJoin('art_lots', function ($join): void {
                $join->on('art_lots.artwork_id', '=', 'artworks.id')
                     ->whereIn('art_lots.status', ['active', 'scheduled']);
            });

            // Effective price: buy_now_price_cents ?? starting_bid_cents
            $effectivePrice = 'COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents)';

            if (isset($validated['min_price'])) {
                $query->whereRaw("{$effectivePrice} >= ?", [(int) $validated['min_price']]);
            }
            if (isset($validated['max_price'])) {
                $query->whereRaw("{$effectivePrice} <= ?", [(int) $validated['max_price']]);
            }
        }

        // Sort
        $sort = $validated['sort'] ?? 'newest';
        if ($sort === 'price_asc') {
            $query->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) IS NULL ASC')
                  ->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) ASC');
        } elseif ($sort === 'price_desc') {
            $query->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) IS NULL ASC')
                  ->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) DESC');
        } else {
            $query->orderByDesc('artworks.created_at');
        }

        $results = $query
            ->distinct()
            ->paginate(self::PAGE_SIZE, ['artworks.*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data'  => $results->items(),
            'meta'  => [
                'total'        => $results->total(),
                'per_page'     => $results->perPage(),
                'current_page' => $results->currentPage(),
                'last_page'    => $results->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/artworks/{artwork}
     *
     * Public detail view. Only 'listed' artworks.
     */
    public function show(Artwork $artwork): JsonResponse
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        $artwork->load([
            'artist:id,name',
            'sdgClaims:artwork_id,sdg_number,status',
        ]);

        return response()->json(['data' => $artwork]);
    }
}
