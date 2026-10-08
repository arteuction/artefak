<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class GalleryController extends Controller
{
    /**
     * POST /api/v1/galleries
     *
     * Admin/operator creates a new gallery.
     */
    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:200'],
            'type'          => ['nullable', 'in:private,public,institutional,online'],
            'venue_id'      => ['nullable', 'integer', 'exists:venues,id'],
            'website'       => ['nullable', 'url', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:200'],
            'legal_name'    => ['nullable', 'string', 'max:200'],
            'eik'           => ['nullable', 'string', 'max:20'],
            'status'        => ['nullable', 'in:active,inactive'],
        ]);

        $gallery = Gallery::create([
            ...$data,
            'slug'   => Str::slug($data['name']) . '-' . time(),
            'status' => $data['status'] ?? 'active',
            'type'   => $data['type'] ?? 'private',
        ]);

        return response()->json($gallery, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Gallery::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'data' => $query->orderBy('name')->paginate(25),
        ]);
    }

    public function show(Gallery $gallery): JsonResponse
    {
        return response()->json(['data' => $gallery]);
    }

    /**
     * GET /api/v1/galleries/{gallery}/profile
     *
     * Enriched public profile: active lots, current exhibitions, staff roster.
     */
    public function profile(Gallery $gallery): JsonResponse
    {
        $gallery->load('venue:id,name,city,country');

        $activeLots = $gallery->artLots()
            ->whereIn('status', ['active', 'scheduled', 'catalogued'])
            ->with('auctionItem:id,art_lot_id,status,current_bid_cents,ends_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'gallery_id', 'status', 'sale_mode', 'buy_now_price_cents', 'currency', 'created_at']);

        // Exhibitions that belong to this gallery's venue (if venue is set)
        $exhibitions = $gallery->venue_id
            ? \App\Models\Exhibition::where('venue_id', $gallery->venue_id)
                ->where('is_active', true)
                ->orderBy('starts_at')
                ->get(['id', 'title', 'slug', 'starts_at', 'ends_at'])
            : collect();

        $staff = $gallery->activeStaff()
            ->with('user:id,name')
            ->get(['id', 'gallery_id', 'user_id', 'role', 'status', 'accepted_at']);

        return response()->json([
            'gallery'     => $gallery,
            'active_lots' => $activeLots,
            'exhibitions' => $exhibitions,
            'staff'       => $staff,
        ]);
    }
}
