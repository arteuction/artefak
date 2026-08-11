<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuctionController extends Controller
{
    /** GET /api/auctions — published or live auctions, paginated. */
    public function index(Request $request): JsonResponse
    {
        $auctions = Auction::whereIn('status', ['published', 'live'])
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json($auctions);
    }

    /** GET /api/auctions/{auction} — single auction with its lots. */
    public function show(Auction $auction): JsonResponse
    {
        $auction->load([
            'venue.locality',
            'items' => fn ($q) => $q->with(['artwork', 'winningBid']),
        ]);

        return response()->json($auction);
    }

    /** GET /api/auctions/{auction}/items/{item} — single lot with bid history. */
    public function item(Auction $auction, AuctionItem $item): JsonResponse
    {
        abort_if($item->auction_id !== $auction->id, 404);

        $item->load(['artwork.artist', 'bids' => fn ($q) => $q->where('status', 'accepted')->orderByDesc('amount_cents')]);

        return response()->json([
            'item'           => $item,
            'next_bid_cents' => $item->nextBidCents(),
        ]);
    }
}
