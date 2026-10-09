<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArtistProfile;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public artist portfolio — profile + listed artworks + recent auction results.
 * Only approved artist profiles are exposed.
 */
final class ArtistPortfolioController extends Controller
{
    /**
     * GET /api/v1/artists/{slug}
     *
     * Public profile: bio, website, social, listed artworks (paginated 20),
     * and up to 5 recent sold auction items.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $profile = ArtistProfile::where('slug', $slug)
            ->where('status', 'approved')
            ->firstOrFail();

        $artworks = Artwork::where('user_id', $profile->user_id)
            ->where('status', 'listed')
            ->orderByDesc('created_at')
            ->paginate(20);

        $recentSales = Artwork::where('artworks.user_id', $profile->user_id)
            ->where('artworks.status', 'sold')
            ->join('art_lots', 'art_lots.artwork_id', '=', 'artworks.id')
            ->join('auction_items', 'auction_items.art_lot_id', '=', 'art_lots.id')
            ->where('auction_items.status', 'sold')
            ->select([
                'artworks.id',
                'artworks.title',
                'artworks.slug',
                'auction_items.id as auction_item_id',
                'auction_items.status as auction_item_status',
            ])
            ->orderByDesc('auction_items.updated_at')
            ->limit(5)
            ->get();

        return response()->json([
            'profile' => [
                'id'               => $profile->id,
                'display_name'     => $profile->display_name,
                'slug'             => $profile->slug,
                'bio'              => $profile->bio,
                'website'          => $profile->website,
                'instagram_handle' => $profile->instagram_handle,
            ],
            'artworks' => [
                'data' => $artworks->items(),
                'meta' => [
                    'total'        => $artworks->total(),
                    'current_page' => $artworks->currentPage(),
                    'last_page'    => $artworks->lastPage(),
                ],
            ],
            'recent_sales' => $recentSales,
        ]);
    }

    /**
     * GET /api/v1/artists
     *
     * Paginated list of approved artist profiles for browsing.
     */
    public function index(Request $request): JsonResponse
    {
        $profiles = ArtistProfile::where('status', 'approved')
            ->select(['id', 'display_name', 'slug', 'bio', 'instagram_handle'])
            ->orderBy('display_name')
            ->paginate(30);

        return response()->json([
            'data' => $profiles->items(),
            'meta' => [
                'total'        => $profiles->total(),
                'current_page' => $profiles->currentPage(),
                'last_page'    => $profiles->lastPage(),
            ],
        ]);
    }
}
