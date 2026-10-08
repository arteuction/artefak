<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImpactEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImpactEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ImpactEvent::query();

        if ($request->filled('sdg_number')) {
            $query->where('sdg_number', $request->integer('sdg_number'));
        }

        if ($request->filled('metric')) {
            $query->where('metric', $request->metric);
        }

        if ($request->filled('art_lot_id')) {
            $query->where('art_lot_id', $request->integer('art_lot_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate(25),
        ]);
    }

    public function show(ImpactEvent $impactEvent): JsonResponse
    {
        return response()->json(['data' => $impactEvent]);
    }
}
