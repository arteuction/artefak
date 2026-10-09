<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settlement\GenerateSettlementStatement;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Admin view of the settlements ledger (read-only).
 * Financial records are append-only; no PATCH/DELETE.
 */
final class SettlementController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/admin/settlements
     *
     * Paginated settlement list, filterable by status and auction_id.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $query = DB::table('settlements')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('auction_id')) {
            $query->where('auction_id', (int) $request->input('auction_id'));
        }

        $total = $query->count();
        $page  = max(1, (int) $request->input('page', 1));
        $perPage = 50;
        $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data'    => $items,
            'total'   => $total,
            'page'    => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /api/v1/admin/settlements/{id}
     *
     * Single settlement with its lines.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);

        $settlement = DB::table('settlements')->find($id);

        if (! $settlement) {
            abort(404, "Settlement #{$id} not found.");
        }

        $lines = DB::table('settlement_lines')->where('settlement_id', $id)->get();

        return response()->json([
            'settlement' => $settlement,
            'lines'      => $lines,
        ]);
    }

    /**
     * GET /api/v1/admin/settlements/{id}/statement
     *
     * Downloads a PDF settlement statement for accounting purposes.
     */
    public function statement(Request $request, int $id): Response
    {
        $this->requireAdmin($request);

        try {
            return (new GenerateSettlementStatement())->execute($id);
        } catch (\InvalidArgumentException) {
            abort(404);
        }
    }
}
