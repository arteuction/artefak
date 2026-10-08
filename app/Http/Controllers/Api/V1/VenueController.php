<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Venue::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json([
            'data' => $query->orderBy('name')->paginate(25),
        ]);
    }

    public function show(Venue $venue): JsonResponse
    {
        return response()->json(['data' => $venue]);
    }

    /**
     * POST /api/v1/venues
     *
     * Admin/operator creates a new venue.
     */
    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:200'],
            'type'            => ['nullable', 'in:state,private,commercial,metro'],
            'geo_locality_id' => ['required', 'integer', 'exists:geo_localities,id'],
            'address'         => ['nullable', 'string', 'max:500'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email', 'max:120'],
            'website'         => ['nullable', 'url', 'max:255'],
            'description'     => ['nullable', 'string', 'max:5000'],
        ]);

        $venue = Venue::create([
            ...$data,
            'slug' => \Illuminate\Support\Str::slug($data['name']) . '-' . time(),
            'type' => $data['type'] ?? 'private',
        ]);

        return response()->json($venue, 201);
    }

    /**
     * PATCH /api/v1/venues/{venue}
     *
     * Admin/operator updates venue details.
     */
    public function update(Request $request, Venue $venue): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:200'],
            'address'     => ['nullable', 'string', 'max:500'],
            'phone'       => ['nullable', 'string', 'max:30'],
            'email'       => ['nullable', 'email', 'max:120'],
            'website'     => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $venue->update($data);

        return response()->json($venue->fresh());
    }
}
