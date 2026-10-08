<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Financial\RunReconciliation;
use App\Http\Controllers\Controller;
use App\Models\ReconciliationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin: trigger and view reconciliation runs.
 */
final class ReconciliationController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/reconciliation-runs
     *
     * Paginated list, filterable by status.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = ReconciliationRun::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate(25));
    }

    /**
     * GET /api/v1/admin/reconciliation-runs/{run}
     */
    public function show(Request $request, ReconciliationRun $reconciliationRun): JsonResponse
    {
        $this->requireAdmin($request);

        return response()->json(['data' => $reconciliationRun]);
    }

    /**
     * POST /api/v1/admin/reconciliation-runs
     *
     * Trigger a manual reconciliation for a date range.
     * The Stripe totals are passed by the caller (fetched from Stripe outside this endpoint).
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'period_start'             => ['required', 'date'],
            'period_end'               => ['required', 'date', 'after_or_equal:period_start'],
            'stripe_received_cents'    => ['required', 'integer', 'min:0'],
            'stripe_transferred_cents' => ['required', 'integer', 'min:0'],
        ]);

        $run = (new RunReconciliation())->execute(
            periodStart:             new \DateTimeImmutable($data['period_start']),
            periodEnd:               new \DateTimeImmutable($data['period_end']),
            stripeReceivedCents:     (int) $data['stripe_received_cents'],
            stripeTransferredCents:  (int) $data['stripe_transferred_cents'],
            runBy:                   $request->user(),
        );

        return response()->json(['data' => $run], 201);
    }
}
