<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtmetroRoute;
use Illuminate\Http\JsonResponse;

class ArtmetroRouteController extends Controller
{
    /**
     * GET /api/routes
     *
     * Lists all published ArtMetro routes with stop count.
     */
    public function index(): JsonResponse
    {
        $routes = ArtmetroRoute::published()
            ->withCount('stops')
            ->orderBy('title')
            ->get()
            ->map(fn (ArtmetroRoute $r) => [
                'id'                  => $r->id,
                'title'               => $r->title,
                'slug'                => $r->slug,
                'difficulty'          => $r->difficulty,
                'walking_distance_km' => $r->walking_distance_km,
                'accessible'          => $r->accessible,
                'stops_count'         => $r->stops_count,
            ]);

        return response()->json(['routes' => $routes]);
    }

    /**
     * GET /api/routes/{route}
     *
     * Returns route details with ordered venue stops.
     */
    public function show(ArtmetroRoute $route): JsonResponse
    {
        if (! $route->is_published) {
            return response()->json(['message' => 'Route not found.'], 404);
        }

        $route->load(['stops.venue.geoLocality']);

        return response()->json([
            'route' => [
                'id'                  => $route->id,
                'title'               => $route->title,
                'slug'                => $route->slug,
                'description'         => $route->description,
                'difficulty'          => $route->difficulty,
                'walking_distance_km' => $route->walking_distance_km,
                'accessible'          => $route->accessible,
                'stops'               => $route->stops->map(fn ($stop) => [
                    'sort_order' => $stop->sort_order,
                    'notes'      => $stop->notes,
                    'venue'      => [
                        'id'       => $stop->venue->id,
                        'name'     => $stop->venue->name,
                        'type'     => $stop->venue->type,
                        'locality' => $stop->venue->geoLocality?->name,
                    ],
                ]),
            ],
        ]);
    }
}
