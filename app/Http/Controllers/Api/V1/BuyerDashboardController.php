<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuctionItem;
use App\Models\Bid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer-facing dashboard endpoints.
 *
 * All routes are authenticated (Sanctum). The buyer only sees their own data.
 */
final class BuyerDashboardController extends Controller
{
    /**
     * GET /api/v1/my/won-items
     *
     * Auction items the authenticated user won, with fulfillment status.
     * Ordered by payment_deadline ascending (most urgent first).
     */
    public function wonItems(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = AuctionItem::query()
            ->whereHas('bids', fn ($q) => $q->where('user_id', $user->id)->where('status', 'won'))
            ->with([
                'artLot:id,artwork_id,sale_mode,buy_now_price_cents,starting_bid_cents,currency',
                'artLot.artwork:id,title,slug,medium',
                'winningBid:id,user_id,amount_cents,status',
                'auction:id,title,slug,ends_at',
            ])
            ->orderByRaw('payment_deadline IS NULL ASC')
            ->orderBy('payment_deadline', 'asc')
            ->paginate(20);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'total'        => $items->total(),
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/my/bids
     *
     * All bids placed by the authenticated user, most recent first.
     * Includes the auction item and artwork for context.
     */
    public function myBids(Request $request): JsonResponse
    {
        $user = $request->user();

        $bids = Bid::query()
            ->where('user_id', $user->id)
            ->with([
                'auctionItem:id,auction_id,art_lot_id,lot_number,status,fulfillment_status,winning_bid_id',
                'auctionItem.artLot:id,artwork_id',
                'auctionItem.artLot.artwork:id,title,slug',
                'auctionItem.auction:id,title,slug',
            ])
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json([
            'data' => $bids->items(),
            'meta' => [
                'total'        => $bids->total(),
                'current_page' => $bids->currentPage(),
                'last_page'    => $bids->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/auction-items/{item}/bid-history
     *
     * Public bid history for an auction item (amounts only — no bidder identity).
     * Only accessible once item is not 'open' (i.e., after bidding closes).
     * While live, only the authenticated user's own bids are shown.
     */
    public function bidHistory(Request $request, AuctionItem $item): JsonResponse
    {
        $user = $request->user();
        $isLive = $item->status === 'open';

        $query = Bid::query()
            ->where('auction_item_id', $item->id)
            ->select(['id', 'auction_item_id', 'amount_cents', 'status', 'created_at'])
            // deliberately excludes user_id — bidder identity is private
            ->orderByDesc('amount_cents');

        if ($isLive && $user !== null) {
            // While live: return only the caller's bids (hides other bidders)
            $query->where('user_id', $user->id);
        } elseif ($isLive) {
            // Unauthenticated + live: empty (bidding activity is private)
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }
        // After close: all bids are public (no bidder identity — just amounts/status)

        $bids = $query->paginate(50);

        return response()->json([
            'data' => $bids->items(),
            'meta' => [
                'total'        => $bids->total(),
                'current_page' => $bids->currentPage(),
                'last_page'    => $bids->lastPage(),
            ],
        ]);
    }
}
