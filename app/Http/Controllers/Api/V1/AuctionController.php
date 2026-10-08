<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionItem;
use Illuminate\Http\JsonResponse;

final class AuctionController extends Controller
{
    /** GET /api/v1/auctions */
    public function index(): JsonResponse
    {
        $auctions = Auction::whereIn('status', ['published', 'live'])
            ->with('venue:id,name')
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json($auctions);
    }

    /** GET /api/v1/auctions/{auction} */
    public function show(Auction $auction): JsonResponse
    {
        $auction->load([
            'venue.locality',
            'ruleset',
            'items' => fn ($q) => $q->with(['artLot.artwork:id,title,slug', 'winningBid']),
        ]);

        return response()->json([
            'data' => $auction,
        ]);
    }

    /** GET /api/v1/auctions/{auction}/items/{item} */
    public function item(Auction $auction, AuctionItem $item): JsonResponse
    {
        abort_if($item->auction_id !== $auction->id, 404);

        $item->load([
            'artLot.artwork.artist:id,name',
            'artLot.artworkRevision',
            'artLot.artwork.approvedSdgClaims',
            'bids' => fn ($q) => $q->where('status', 'accepted')->orderByDesc('amount_cents'),
        ]);

        return response()->json([
            'data'           => $item,
            'next_bid_cents' => $item->nextBidCents(),
        ]);
    }
}
