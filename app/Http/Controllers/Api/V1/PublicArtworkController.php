<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Metadata\SchemaOrgArtwork;
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
     *   q         — full-text search on title + description
     *   medium    — one of painting|sculpture|photography|digital|nft|mixed|other
     *   sdg       — integer 1–17 (UN SDG number)
     *   min_price — price floor in cents (against art_lots.buy_now_price_cents or starting_bid_cents)
     *   max_price — price ceiling in cents
     *   sort      — newest (default) | price_asc | price_desc
     *   page      — pagination page (default 1; ignored when cursor is present)
     *   cursor    — opaque keyset cursor for the next page (only valid with sort=newest)
     *
     * When cursor is supplied and sort=newest, cursor/keyset pagination is used instead of
     * OFFSET-based pagination: no COUNT(*) query, O(1) seek via (created_at, id) index.
     * The response meta will include next_cursor (null if no further pages exist) and omit
     * total/last_page (which require COUNT and are unavailable in keyset mode).
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
            'cursor'    => ['nullable', 'string', 'max:500'],
        ]);

        $query = Artwork::query()
            ->where('artworks.status', 'listed')
            ->select('artworks.*')
            ->with(['artist:id,name']);

        // Full-text search: MATCH AGAINST in production (uses migration-077 FULLTEXT index).
        // Falls back to LIKE in testing — RefreshDatabase recreates the schema but
        // FULLTEXT indexes on empty tables return no rows in some MariaDB/MySQL versions.
        // When SCOUT_DRIVER=meilisearch, replace this with Artwork::search()->keys().
        if (! empty($validated['q'])) {
            if (app()->environment('testing')) {
                $term = '%' . $validated['q'] . '%';
                $query->where(function ($q) use ($term): void {
                    $q->where('artworks.title', 'LIKE', $term)
                      ->orWhere('artworks.description', 'LIKE', $term);
                });
            } else {
                $query->whereRaw(
                    'MATCH(artworks.title, artworks.description) AGAINST (? IN BOOLEAN MODE)',
                    [$validated['q']]
                );
            }
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

        $sort = $validated['sort'] ?? 'newest';

        // ── Cursor / keyset pagination (newest sort only) ────────────────────
        // Avoids COUNT(*) and OFFSET. Uses (created_at DESC, id DESC) seek.
        // Only offered for the default 'newest' sort; price sorts use OFFSET.
        if ($sort === 'newest' && ! empty($validated['cursor'])) {
            return $this->indexWithCursor($query, $validated['cursor']);
        }

        // ── Sort ─────────────────────────────────────────────────────────────
        if ($sort === 'price_asc') {
            $query->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) IS NULL ASC')
                  ->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) ASC');
        } elseif ($sort === 'price_desc') {
            $query->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) IS NULL ASC')
                  ->orderByRaw('COALESCE(art_lots.buy_now_price_cents, art_lots.starting_bid_cents) DESC');
        } else {
            // newest: add id as a unique tiebreaker so the cursor is stable when
            // two artworks share the same created_at timestamp.
            $query->orderByDesc('artworks.created_at')->orderByDesc('artworks.id');
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
                'next_cursor'  => null,  // cursor mode not active; use page param
            ],
        ]);
    }

    /**
     * Cursor/keyset pagination for the 'newest' sort.
     *
     * Fetches PAGE_SIZE + 1 rows after the cursor position, then uses the extra
     * row to determine whether a next page exists (without a COUNT(*) query).
     *
     * Cursor format (opaque to callers): base64url(json({created_at, id}))
     */
    private function indexWithCursor(\Illuminate\Database\Eloquent\Builder $query, string $rawCursor): JsonResponse
    {
        $cursor = $this->decodeCursor($rawCursor);

        if ($cursor !== null) {
            // Keyset condition: rows strictly after (created_at, id) in DESC order.
            // "(created_at < pivot) OR (created_at = pivot AND id < pivot_id)"
            $query->where(function ($q) use ($cursor): void {
                $q->where('artworks.created_at', '<', $cursor['created_at'])
                  ->orWhere(function ($q2) use ($cursor): void {
                      $q2->where('artworks.created_at', '=', $cursor['created_at'])
                         ->where('artworks.id', '<', $cursor['id']);
                  });
            });
        }

        $query->orderByDesc('artworks.created_at')->orderByDesc('artworks.id');

        // Fetch one extra to detect has_next_page without COUNT(*)
        $rows = $query->distinct()->limit(self::PAGE_SIZE + 1)->get();

        $hasNextPage = $rows->count() > self::PAGE_SIZE;
        $items       = $rows->take(self::PAGE_SIZE);

        $nextCursor = null;
        if ($hasNextPage) {
            $last       = $items->last();
            $nextCursor = $this->encodeCursor($last->created_at->toISOString(), $last->id);
        }

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'per_page'    => self::PAGE_SIZE,
                'next_cursor' => $nextCursor,
                // total and last_page are not available in keyset mode
            ],
        ]);
    }

    private function encodeCursor(string $createdAt, int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['c' => $createdAt, 'i' => $id])), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): ?array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), strict: false);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (! isset($decoded['c'], $decoded['i'])) {
            return null;
        }
        return ['created_at' => (string) $decoded['c'], 'id' => (int) $decoded['i']];
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

        $schemaOrg = (new SchemaOrgArtwork())->build($artwork);

        return response()->json([
            'data'      => $artwork,
            'schema_org' => $schemaOrg,
        ]);
    }
}
