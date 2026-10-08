<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DonationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Donation::query();

        if ($user->role !== 'admin') {
            $query->where('donor_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('donation_recipient_id')) {
            $query->where('donation_recipient_id', $request->integer('donation_recipient_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate(25),
        ]);
    }

    public function show(Request $request, Donation $donation): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $donation->donor_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => $donation]);
    }
}
