<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auction\BidRejected;
use App\Domain\Auction\PlaceMaxBid;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * POST /api/v1/auctions/{auction}/items/{item}/max-bid
     *
     * Sets or replaces the authenticated user's proxy bid ceiling on this item.
     * The ceiling is private — only the user and admins see it.
     */
    public function placeMaxBid(Request $request, Auction $auction, AuctionItem $item): JsonResponse
    {
        abort_if($item->auction_id !== $auction->id, 404);

        $data = $request->validate([
            'ceiling_cents' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $maxBid = (new PlaceMaxBid())->execute(
                item:          $item,
                bidderId:      $request->user()->id,
                ceilingCents:  (int) $data['ceiling_cents'],
            );
        } catch (BidRejected $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($maxBid, 201);
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
