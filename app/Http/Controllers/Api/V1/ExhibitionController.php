<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Exhibition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExhibitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Exhibition::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('gallery_id')) {
            $query->where('gallery_id', $request->integer('gallery_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('starts_at')->paginate(25),
        ]);
    }

    public function show(Exhibition $exhibition): JsonResponse
    {
        return response()->json(['data' => $exhibition]);
    }
}
