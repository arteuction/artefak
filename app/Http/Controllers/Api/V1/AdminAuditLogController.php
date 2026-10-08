<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminAuditLogController extends Controller
{
    /**
     * GET /api/v1/admin/audit-log
     *
     * Admin/operator: paginated list of audit entries.
     * Filterable by subject_type, subject_id, actor_id, action.
     */
    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $query = AdminAuditLog::with('actor:id,name,email')->orderByDesc('created_at');

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->input('subject_id'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        return response()->json($query->paginate(50));
    }

    /**
     * GET /api/v1/admin/audit-log/{entry}
     *
     * Admin/operator: single audit entry with full payload.
     */
    public function show(Request $request, AdminAuditLog $adminAuditLog): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $adminAuditLog->load('actor:id,name,email');

        return response()->json(['data' => $adminAuditLog]);
    }
}
