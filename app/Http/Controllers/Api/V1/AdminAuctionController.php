<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auction\CloseAuctionItem;
use App\Http\Controllers\Controller;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin auction lifecycle management.
 *
 * Create, update, and manage items for an auction.
 * Bidding, closing, and settlement are handled by domain actions / scheduled jobs.
 */
final class AdminAuctionController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * POST /api/v1/admin/auctions
     *
     * Create a new auction (draft by default).
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'venue_id'    => ['nullable', 'integer', 'exists:venues,id'],
            'ruleset_id'  => ['nullable', 'integer', 'exists:auction_rulesets,id'],
            'starts_at'   => ['required', 'date'],
            'ends_at'     => ['required', 'date', 'after:starts_at'],
            'currency'    => ['nullable', 'string', 'max:3'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $auction = Auction::create([
            ...$data,
            'slug'     => Str::slug($data['title']) . '-' . time(),
            'status'   => 'draft',
            'currency' => $data['currency'] ?? 'BGN',
        ]);

        return response()->json(['data' => $auction], 201);
    }

    /**
     * PATCH /api/v1/admin/auctions/{auction}
     *
     * Update auction metadata. Cannot update a live or closed auction.
     */
    public function update(Request $request, Auction $auction): JsonResponse
    {
        $this->requireAdmin($request);

        if (in_array($auction->status, ['live', 'closed', 'canceled'], true)) {
            abort(422, "Cannot update an auction in status [{$auction->status}].");
        }

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'venue_id'    => ['nullable', 'integer', 'exists:venues,id'],
            'ruleset_id'  => ['nullable', 'integer', 'exists:auction_rulesets,id'],
            'starts_at'   => ['sometimes', 'date'],
            'ends_at'     => ['sometimes', 'date'],
            'currency'    => ['sometimes', 'string', 'max:3'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status'      => ['sometimes', 'in:draft,published'],
        ]);

        $auction->update($data);

        return response()->json(['data' => $auction->fresh()]);
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/items
     *
     * Add an ArtLot to an auction as a new item.
     * The ArtLot must be in a schedulable state (approved/catalogued/scheduled).
     */
    public function addItem(Request $request, Auction $auction): JsonResponse
    {
        $this->requireAdmin($request);

        if (in_array($auction->status, ['live', 'closed', 'canceled'], true)) {
            abort(422, "Cannot add items to an auction in status [{$auction->status}].");
        }

        $data = $request->validate([
            'art_lot_id'          => ['required', 'integer', 'exists:art_lots,id'],
            'lot_number'          => ['required', 'integer', 'min:1'],
            'bid_increment_cents' => ['required', 'integer', 'min:100'],
        ]);

        $artLot = ArtLot::findOrFail($data['art_lot_id']);

        if (! in_array($artLot->status, ['approved', 'catalogued', 'scheduled', 'draft'], true)) {
            abort(422, "ArtLot [{$artLot->id}] in status [{$artLot->status}] cannot be added to an auction.");
        }

        // Guard against duplicate
        if (AuctionItem::where('auction_id', $auction->id)->where('art_lot_id', $data['art_lot_id'])->exists()) {
            abort(422, 'This lot is already in the auction.');
        }

        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $data['art_lot_id'],
            'lot_number'          => $data['lot_number'],
            'bid_increment_cents' => $data['bid_increment_cents'],
            'status'              => 'pending',
        ]);

        return response()->json(['data' => $item->load('artLot:id,status')], 201);
    }

    /**
     * DELETE /api/v1/admin/auctions/{auction}/items/{item}
     *
     * Remove an item from a draft/published auction. Cannot remove once live.
     */
    public function removeItem(Request $request, Auction $auction, AuctionItem $item): JsonResponse
    {
        $this->requireAdmin($request);

        abort_if($item->auction_id !== $auction->id, 404);

        if ($auction->status === 'live') {
            abort(422, 'Cannot remove items from a live auction.');
        }

        if ($item->status !== 'pending') {
            abort(422, "Cannot remove an item in status [{$item->status}].");
        }

        $item->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/publish
     *
     * Transition: draft → published
     * Auction becomes visible to the public but bidding not yet open.
     */
    public function publish(Request $request, Auction $auction): JsonResponse
    {
        $this->requireAdmin($request);

        if ($auction->status !== 'draft') {
            abort(422, "Auction must be in [draft] to publish. Current status: [{$auction->status}].");
        }

        if (! $auction->items()->where('status', 'pending')->exists()) {
            abort(422, 'Auction must have at least one pending item before publishing.');
        }

        $auction->update(['status' => 'published']);

        return response()->json(['data' => $auction->fresh()]);
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/open
     *
     * Transition: published → live
     * Opens all pending items for bidding.
     */
    public function open(Request $request, Auction $auction): JsonResponse
    {
        $this->requireAdmin($request);

        if ($auction->status !== 'published') {
            abort(422, "Auction must be in [published] to open. Current status: [{$auction->status}].");
        }

        DB::transaction(function () use ($auction): void {
            $auction->update(['status' => 'live']);
            $auction->items()->where('status', 'pending')->update(['status' => 'open']);
        });

        return response()->json(['data' => $auction->fresh()->loadCount('items')]);
    }

    /**
     * POST /api/v1/admin/auctions/{auction}/close
     *
     * Transition: live → closed
     * Runs CloseAuctionItem on every still-open item, then marks auction closed.
     */
    public function close(Request $request, Auction $auction, CloseAuctionItem $closeItem): JsonResponse
    {
        $this->requireAdmin($request);

        if ($auction->status !== 'live') {
            abort(422, "Auction must be in [live] to close. Current status: [{$auction->status}].");
        }

        $openItems = $auction->items()->where('status', 'open')->get();

        foreach ($openItems as $item) {
            $closeItem->execute($item);
        }

        $auction->update(['status' => 'closed']);

        return response()->json(['data' => $auction->fresh()->loadCount('items')]);
    }
}
