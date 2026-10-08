<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArtmetroRoute;
use App\Models\ArtmetroRouteStop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin CRUD for ArtMetro routes and their venue stops.
 * All endpoints require admin/operator role.
 */
final class ArtmetroAdminController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * POST /api/v1/admin/artmetro/routes
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'title'               => ['required', 'string', 'max:200'],
            'description'         => ['nullable', 'string', 'max:5000'],
            'difficulty'          => ['nullable', 'in:easy,moderate,hard'],
            'walking_distance_km' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'accessible'          => ['nullable', 'boolean'],
        ]);

        $route = ArtmetroRoute::create([
            ...$data,
            'slug'         => Str::slug($data['title']) . '-' . time(),
            'is_published' => false,
        ]);

        return response()->json($route, 201);
    }

    /**
     * PATCH /api/v1/admin/artmetro/routes/{route}
     */
    public function update(Request $request, ArtmetroRoute $route): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'title'               => ['sometimes', 'string', 'max:200'],
            'description'         => ['nullable', 'string', 'max:5000'],
            'difficulty'          => ['sometimes', 'in:easy,moderate,hard'],
            'walking_distance_km' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'accessible'          => ['sometimes', 'boolean'],
        ]);

        $route->update($data);

        return response()->json($route->fresh());
    }

    /**
     * PATCH /api/v1/admin/artmetro/routes/{route}/publish
     *
     * Toggle published state.
     */
    public function togglePublish(Request $request, ArtmetroRoute $route): JsonResponse
    {
        $this->requireAdmin($request);

        $route->update(['is_published' => ! $route->is_published]);

        return response()->json($route->fresh());
    }

    /**
     * POST /api/v1/admin/artmetro/routes/{route}/stops
     *
     * Append or insert a venue stop on the route.
     */
    public function addStop(Request $request, ArtmetroRoute $route): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'venue_id'   => ['required', 'integer', 'exists:venues,id'],
            'sort_order' => ['required', 'integer', 'min:1'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $stop = ArtmetroRouteStop::create([
            'route_id'   => $route->id,
            'venue_id'   => $data['venue_id'],
            'sort_order' => $data['sort_order'],
            'notes'      => $data['notes'] ?? null,
        ]);

        return response()->json($stop->load('venue:id,name'), 201);
    }

    /**
     * DELETE /api/v1/admin/artmetro/routes/{route}/stops/{stop}
     */
    public function removeStop(Request $request, ArtmetroRoute $route, ArtmetroRouteStop $stop): JsonResponse
    {
        $this->requireAdmin($request);

        abort_if($stop->route_id !== $route->id, 404);

        $stop->delete();

        return response()->json(null, 204);
    }
}
