<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Watchlist\Unwatch;
use App\Domain\Watchlist\Watch;
use App\Http\Controllers\Controller;
use App\Models\ArtLot;
use App\Models\ArtworkItem;
use App\Models\Artwork;
use App\Models\AuctionItem;
use App\Models\WatchlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WatchlistController extends Controller
{
    private const SUBJECTS = [
        'artwork'      => Artwork::class,
        'auction_item' => AuctionItem::class,
        'art_lot'      => ArtLot::class,
    ];

    /** GET /api/v1/watchlist */
    public function index(Request $request): JsonResponse
    {
        $items = WatchlistItem::with('subject')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(40);

        return response()->json($items);
    }

    /** POST /api/v1/watchlist */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:artwork,auction_item,art_lot'],
            'subject_id'   => ['required', 'integer', 'min:1'],
        ]);

        $modelClass = self::SUBJECTS[$data['subject_type']];
        $subject    = $modelClass::findOrFail((int) $data['subject_id']);

        $item = (new Watch())->execute($request->user(), $subject);

        return response()->json($item, 201);
    }

    /** DELETE /api/v1/watchlist/{type}/{id} */
    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        if (! array_key_exists($type, self::SUBJECTS)) {
            abort(404, "Unknown subject type '{$type}'.");
        }

        $modelClass = self::SUBJECTS[$type];
        $subject    = $modelClass::findOrFail($id);

        (new Unwatch())->execute($request->user(), $subject);

        return response()->json(null, 204);
    }
}
