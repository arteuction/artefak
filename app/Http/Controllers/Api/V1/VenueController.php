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
}
