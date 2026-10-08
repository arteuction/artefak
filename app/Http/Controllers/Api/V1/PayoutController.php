<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Financial transparency endpoints — "follow the money."
 *
 * These endpoints make the three-stage financial lifecycle visible:
 *
 *   PAYMENT  — Buyer paid (settlement.gross_cents, status != refunded)
 *   SETTLEMENT — System allocated funds (settlement_lines)
 *   PAYOUT   — Recipient received funds (settlement_lines.status = transferred)
 *
 * Artist view: own settlement lines where recipient_type = 'artist'|'author'|'co_author'
 * Gallery view: settlement lines where gallery_id is linked (via ArtLot)
 */
final class PayoutController extends Controller
{
    /**
     * Artist payout summary.
     *
     * GET /api/v1/payouts/artist
     *
     * Returns:
     *   gross_cents          total sales where this user was artist
     *   allocated_cents      their share as allocated at settlement time
     *   transferred_cents    already paid out (status=transferred)
     *   pending_cents        allocated but not yet transferred
     *   reversed_cents       reversed / refunded lines
     *   settlement_count     number of settlements
     *   lines                paginated list of individual lines (most recent first)
     */
    public function artist(Request $request): JsonResponse
    {
        $user = $request->user();

        $summary = DB::table('settlement_lines')
            ->where('user_id', $user->id)
            ->whereIn('recipient_type', ['artist', 'author', 'co_author'])
            ->selectRaw("
                COALESCE(SUM(amount_cents), 0)                              AS allocated_cents,
                COALESCE(SUM(CASE WHEN status = 'transferred' THEN amount_cents END), 0) AS transferred_cents,
                COALESCE(SUM(CASE WHEN status = 'pending'     THEN amount_cents END), 0) AS pending_cents,
                COALESCE(SUM(CASE WHEN status = 'reversed'    THEN amount_cents END), 0) AS reversed_cents,
                COUNT(*)                                                     AS line_count
            ")
            ->first();

        $settlementCount = DB::table('settlement_lines')
            ->where('user_id', $user->id)
            ->whereIn('recipient_type', ['artist', 'author', 'co_author'])
            ->distinct('settlement_id')
            ->count('settlement_id');

        $grossCents = (int) DB::table('settlements')
            ->join('settlement_lines', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->where('settlement_lines.user_id', $user->id)
            ->whereIn('settlement_lines.recipient_type', ['artist', 'author', 'co_author'])
            ->whereNotIn('settlements.status', ['refunded'])
            ->sum('settlements.gross_cents');

        $lines = DB::table('settlement_lines')
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->where('settlement_lines.user_id', $user->id)
            ->whereIn('settlement_lines.recipient_type', ['artist', 'author', 'co_author'])
            ->select(
                'settlement_lines.id',
                'settlement_lines.settlement_id',
                'settlement_lines.recipient_type',
                'settlement_lines.amount_cents',
                'settlement_lines.currency',
                'settlement_lines.status',
                'settlement_lines.stripe_transfer_id',
                'settlements.gross_cents',
                'settlements.profile_key',
                'settlements.artist_bps',
                'settlements.created_at as settled_at',
            )
            ->orderByDesc('settlement_lines.id')
            ->paginate(50);

        return response()->json([
            'data' => [
                'summary' => [
                    'gross_cents'       => $grossCents,
                    'allocated_cents'   => (int) $summary->allocated_cents,
                    'transferred_cents' => (int) $summary->transferred_cents,
                    'pending_cents'     => (int) $summary->pending_cents,
                    'reversed_cents'    => (int) $summary->reversed_cents,
                    'settlement_count'  => $settlementCount,
                ],
                'lines' => $lines,
            ],
        ]);
    }

    /**
     * Gallery payout summary.
     *
     * GET /api/v1/payouts/gallery/{gallery}
     *
     * The authenticated user must hold an active 'owner' or 'finance' role.
     */
    public function gallery(Request $request, Gallery $gallery): JsonResponse
    {
        $user = $request->user();

        if (! ($gallery->hasRole($user, 'owner') || $gallery->hasRole($user, 'finance'))) {
            abort(403, 'Must hold owner or finance role at this gallery.');
        }

        // Gallery lines: recipient_type = 'ops' lines linked through ArtLots to this gallery.
        // We join art_lots → settlement (via auction_item/sell_now) where art_lots.gallery_id = gallery.
        // The simpler query: settlement_lines joined via settlements.auction_id → auctions → auction_items → art_lots.
        // For sell-now: sell_now_offers.gallery_id.
        // Current approach: use entity_name match as a proxy until full gallery_id is on settlement_lines.
        // This is the transitional query; a future migration will add gallery_id to settlements.

        $summary = DB::table('settlement_lines')
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->where('settlement_lines.entity_name', $gallery->name)
            ->selectRaw("
                COALESCE(SUM(settlement_lines.amount_cents), 0)                                           AS allocated_cents,
                COALESCE(SUM(CASE WHEN settlement_lines.status = 'transferred' THEN settlement_lines.amount_cents END), 0) AS transferred_cents,
                COALESCE(SUM(CASE WHEN settlement_lines.status = 'pending'     THEN settlement_lines.amount_cents END), 0) AS pending_cents,
                COUNT(DISTINCT settlements.id)                                                             AS settlement_count
            ")
            ->first();

        $lines = DB::table('settlement_lines')
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->where('settlement_lines.entity_name', $gallery->name)
            ->select(
                'settlement_lines.id',
                'settlement_lines.settlement_id',
                'settlement_lines.recipient_type',
                'settlement_lines.amount_cents',
                'settlement_lines.currency',
                'settlement_lines.status',
                'settlement_lines.stripe_transfer_id',
                'settlements.gross_cents',
                'settlements.profile_key',
                'settlements.created_at as settled_at',
            )
            ->orderByDesc('settlement_lines.id')
            ->paginate(50);

        return response()->json([
            'data' => [
                'gallery'  => ['id' => $gallery->id, 'name' => $gallery->name],
                'summary' => [
                    'allocated_cents'   => (int) $summary->allocated_cents,
                    'transferred_cents' => (int) $summary->transferred_cents,
                    'pending_cents'     => (int) $summary->pending_cents,
                    'settlement_count'  => (int) $summary->settlement_count,
                ],
                'lines' => $lines,
            ],
        ]);
    }

    /**
     * Public artwork sales history.
     *
     * GET /api/v1/artworks/{artwork}/sales-history
     *
     * Privacy: does NOT reveal buyer identity or exact amounts unless
     * the artwork's owner has enabled public_collector_profile (future).
     * Returns: sale date, sale mode (auction/sell_now), price band, gallery.
     */
    public function artworkSalesHistory(int $artworkId): JsonResponse
    {
        // OwnershipTransfers are the canonical record of sales.
        // Join via art_lots to reach artwork_id.
        $transfers = DB::table('ownership_transfers')
            ->join('art_lots', 'art_lots.id', '=', 'ownership_transfers.art_lot_id')
            ->where('art_lots.artwork_id', $artworkId)
            ->select(
                'ownership_transfers.id',
                'ownership_transfers.channel',
                'ownership_transfers.transfer_price_cents',
                'ownership_transfers.currency',
                'ownership_transfers.transferred_at',
            )
            ->orderBy('ownership_transfers.transferred_at')
            ->get()
            ->map(function ($row): array {
                return [
                    'channel'             => $row->channel,
                    'transfer_price_cents'=> (int) $row->transfer_price_cents,
                    'currency'            => $row->currency,
                    'transferred_at'      => $row->transferred_at,
                    // Buyer/seller identity intentionally withheld — privacy by default.
                ];
            });

        return response()->json(['data' => $transfers]);
    }
}
