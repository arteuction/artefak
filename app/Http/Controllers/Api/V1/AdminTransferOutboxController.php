<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin read-only view of the transfer outbox.
 *
 * The transfer outbox tracks Stripe Connect transfers (one per settlement line).
 * Retry logic lives in DispatchStripeTransfer; manual retry via the ops console.
 */
final class AdminTransferOutboxController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/transfer-outbox
     *
     * Paginated list of outbox rows. Filterable by status.
     * Returns failed/pending rows first for operator triage.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = DB::table('transfer_outbox')
            ->orderByRaw("FIELD(status, 'failed', 'pending', 'processing', 'dispatched')")
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $total   = $query->count();
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 100;
        $items   = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $summary = DB::table('transfer_outbox')
            ->selectRaw("
                status,
                COUNT(*) AS cnt,
                COALESCE(SUM(amount_cents), 0) AS total_cents
            ")
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return response()->json([
            'data'    => $items,
            'total'   => $total,
            'summary' => $summary,
        ]);
    }

    /** GET /api/v1/admin/transfer-outbox/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);

        $row = DB::table('transfer_outbox')->find($id);

        if (! $row) {
            abort(404);
        }

        $line = DB::table('settlement_lines')->find($row->settlement_line_id);

        return response()->json(['data' => $row, 'settlement_line' => $line]);
    }
}
