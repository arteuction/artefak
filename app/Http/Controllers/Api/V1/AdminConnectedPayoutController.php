<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccountPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin read-only view of connected-account payouts.
 *
 * Stripe Payouts (po_xxx) move funds from a connected account to its bank.
 * They are distinct from Transfers and do not map 1-to-1 with settlements.
 * This view is for ops reconciliation and failure triage only.
 */
final class AdminConnectedPayoutController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/connected-payouts
     *
     * Filterable by status and stripe_account_id.
     * Failed payouts listed first for triage.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = ConnectedAccountPayout::orderByRaw("FIELD(status, 'failed', 'canceled', 'paid')")
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('account')) {
            $query->where('stripe_account_id', $request->input('account'));
        }

        $perPage = 100;
        $page    = max(1, (int) $request->input('page', 1));
        $total   = $query->count();
        $items   = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $summary = ConnectedAccountPayout::selectRaw(
            "status, COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS total_cents"
        )->groupBy('status')->get()->keyBy('status');

        return response()->json([
            'data'    => $items,
            'total'   => $total,
            'summary' => $summary,
        ]);
    }

    /** GET /api/v1/admin/connected-payouts/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);

        $payout = ConnectedAccountPayout::findOrFail($id);

        return response()->json(['data' => $payout]);
    }
}
