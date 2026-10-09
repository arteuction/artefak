<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ReconciliationMismatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin: list and resolve reconciliation mismatches.
 */
final class ReconciliationMismatchController
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/reconciliation-mismatches
     *
     * Paginated list, filterable by check_type and resolution_status.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = ReconciliationMismatch::with('run')->orderByDesc('created_at');

        if ($request->filled('check_type')) {
            $query->where('check_type', $request->input('check_type'));
        }

        if ($request->filled('resolution_status')) {
            $query->where('resolution_status', $request->input('resolution_status'));
        }

        if ($request->filled('reconciliation_run_id')) {
            $query->where('reconciliation_run_id', $request->input('reconciliation_run_id'));
        }

        $paginated = $query->paginate(50);

        return response()->json([
            'data'         => $paginated->items(),
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ]);
    }

    /**
     * GET /api/v1/admin/reconciliation-mismatches/{mismatch}
     */
    public function show(Request $request, ReconciliationMismatch $reconciliationMismatch): JsonResponse
    {
        $this->requireAdmin($request);

        return response()->json(['data' => $reconciliationMismatch->load('run')]);
    }

    /**
     * PATCH /api/v1/admin/reconciliation-mismatches/{mismatch}
     *
     * Move a mismatch through its resolution lifecycle.
     * Allowed transitions: open → investigating → resolved | suppressed
     */
    public function update(Request $request, ReconciliationMismatch $reconciliationMismatch): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'resolution_status' => ['required', Rule::in(['investigating', 'resolved', 'suppressed'])],
            'resolution_notes'  => ['nullable', 'string', 'max:2000'],
        ]);

        $current = $reconciliationMismatch->resolution_status;
        $next    = $data['resolution_status'];

        // Guard illegal backward transitions
        $allowedFrom = match ($next) {
            'investigating' => ['open', 'investigating'],
            'resolved'      => ['open', 'investigating', 'resolved'],
            'suppressed'    => ['open', 'investigating', 'suppressed'],
            default         => [],
        };

        if (! in_array($current, $allowedFrom, true)) {
            return response()->json([
                'message' => "Cannot transition from '{$current}' to '{$next}'.",
            ], 422);
        }

        $updates = ['resolution_status' => $next];

        if (isset($data['resolution_notes'])) {
            $updates['resolution_notes'] = $data['resolution_notes'];
        }

        if (in_array($next, ['resolved', 'suppressed'], true)) {
            $updates['resolved_at'] = now();
        }

        $reconciliationMismatch->update($updates);

        return response()->json(['data' => $reconciliationMismatch->fresh()]);
    }
}
