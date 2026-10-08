<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin read-only view of the fund ledger.
 * Ledger entries are append-only — no PATCH/DELETE.
 */
final class LedgerController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/ledger
     *
     * Paginated ledger entries. Filterable by type, settlement_id.
     * Returns running balance summary.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = DB::table('ledger_entries')->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('settlement_id')) {
            $query->where('settlement_id', (int) $request->input('settlement_id'));
        }

        $total = $query->count();
        $page  = max(1, (int) $request->input('page', 1));
        $perPage = 100;
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Running balance
        $totalCredits = (int) DB::table('ledger_entries')->where('type', 'credit')->sum('amount_cents');
        $totalDebits  = (int) DB::table('ledger_entries')->where('type', 'debit')->sum('amount_cents');

        return response()->json([
            'data'            => $items,
            'total'           => $total,
            'balance_cents'   => $totalCredits - $totalDebits,
            'total_credits'   => $totalCredits,
            'total_debits'    => $totalDebits,
        ]);
    }

    /**
     * GET /api/v1/admin/refunds
     *
     * Paginated refund list. Filterable by refund_status, settlement_id.
     */
    public function refunds(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = DB::table('refunds')->orderByDesc('created_at');

        if ($request->filled('refund_status')) {
            $query->where('refund_status', $request->input('refund_status'));
        }

        if ($request->filled('settlement_id')) {
            $query->where('settlement_id', (int) $request->input('settlement_id'));
        }

        $total = $query->count();
        $items = $query->limit(100)->get();

        return response()->json(['data' => $items, 'total' => $total]);
    }
}
