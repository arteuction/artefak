<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GalleryController extends Controller
{
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
}
