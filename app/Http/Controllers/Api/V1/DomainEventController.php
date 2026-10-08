<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DomainEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DomainEventController extends Controller
{
    /** GET /api/v1/domain-events — operator view of the outbox queue */
    public function index(Request $request): JsonResponse
    {
        $query = DomainEvent::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('aggregate_type')) {
            $query->where('aggregate_type', $request->input('aggregate_type'));
        }

        $events = $query->orderByDesc('created_at')->paginate(50);

        return response()->json($events);
    }
}
