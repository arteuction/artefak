<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

/**
 * Phase 96 — Venue map data for Leaflet.
 *
 * Returns active venues as GeoJSON-compatible FeatureCollection so the
 * frontend Leaflet map can render pins without knowing the API shape.
 */
final class VenueMapController extends Controller
{
    /**
     * GET /api/v1/venues/map
     *
     * Returns a GeoJSON FeatureCollection of all active venues that have
     * latitude/longitude coordinates set.
     */
    public function index(): JsonResponse
    {
        $venues = Venue::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'address', 'latitude', 'longitude']);

        $features = $venues->map(fn (Venue $v) => [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [(float) $v->longitude, (float) $v->latitude],
            ],
            'properties' => [
                'id'      => $v->id,
                'name'    => $v->name,
                'slug'    => $v->slug,
                'address' => $v->address,
            ],
        ]);

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features->values(),
        ]);
    }
}
