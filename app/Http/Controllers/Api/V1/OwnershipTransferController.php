<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OwnershipTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnershipTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = OwnershipTransfer::query();

        // Regular users see only transfers where they are buyer or seller.
        // Admins see everything.
        if ($user->role !== 'admin') {
            $query->where(function ($q) use ($user): void {
                $q->where('from_user_id', $user->id)
                  ->orWhere('to_user_id', $user->id);
            });
        }

        if ($request->filled('art_lot_id')) {
            $query->where('art_lot_id', $request->integer('art_lot_id'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        return response()->json([
            'data' => $query->orderByDesc('transferred_at')->paginate(25),
        ]);
    }

    public function show(Request $request, OwnershipTransfer $ownershipTransfer): JsonResponse
    {
        $user = $request->user();

        if (
            $user->role !== 'admin'
            && $ownershipTransfer->from_user_id !== $user->id
            && $ownershipTransfer->to_user_id   !== $user->id
        ) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => $ownershipTransfer]);
    }
}
