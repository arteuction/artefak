<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchStripeTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin payout disbursement — trigger Stripe transfers for pending settlement lines.
 */
final class AdminPayoutController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/settlement-lines
     *
     * Paginated settlement lines. Filterable by status, recipient_type.
     */
    public function lines(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = DB::table('settlement_lines')->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('recipient_type')) {
            $query->where('recipient_type', $request->input('recipient_type'));
        }

        $total = $query->count();
        $items = $query->limit(100)->get();

        return response()->json(['data' => $items, 'total' => $total]);
    }

    /**
     * POST /api/v1/admin/settlement-lines/{id}/disburse
     *
     * Enqueue a DispatchStripeTransfer job for a pending settlement line.
     * The line must have an associated pending transfer_outbox row.
     */
    public function disburse(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);

        $line = DB::table('settlement_lines')->find($id);

        if (! $line) {
            abort(404, 'Settlement line not found.');
        }

        if ($line->status !== 'pending') {
            abort(422, "Cannot disburse a line in status [{$line->status}].");
        }

        $outbox = DB::table('transfer_outbox')
            ->where('settlement_line_id', $id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if (! $outbox) {
            abort(422, 'No pending transfer_outbox row found for this settlement line.');
        }

        DispatchStripeTransfer::dispatch($outbox->id);

        return response()->json([
            'message'   => 'Transfer queued.',
            'outbox_id' => $outbox->id,
            'line_id'   => $id,
        ]);
    }
}
