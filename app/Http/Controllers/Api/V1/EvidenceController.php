<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Evidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evidence management — verification and subject-scoped listing.
 */
final class EvidenceController extends Controller
{
    /**
     * GET /api/v1/evidence
     *
     * Admin/operator: list evidence, filterable by subject type/id, verification_status.
     */
    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $query = Evidence::query()->orderByDesc('created_at');

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->input('subject_id'));
        }

        if ($request->filled('verification_status')) {
            $query->where('verification_status', $request->input('verification_status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json($query->paginate(25));
    }

    /**
     * GET /api/v1/evidence/{evidence}
     *
     * Admin/operator: show a single evidence record.
     */
    public function show(Request $request, Evidence $evidence): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        return response()->json($evidence);
    }

    /**
     * POST /api/v1/evidence/{evidence}/verify
     *
     * Admin/operator: verify or reject an evidence record.
     *
     * Body: { "status": "verified"|"rejected", "notes": "optional" }
     */
    public function verify(Request $request, Evidence $evidence): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ]);

        if (! in_array($evidence->verification_status, ['pending'], true)) {
            abort(422, "Evidence is already {$evidence->verification_status}; cannot re-verify.");
        }

        $evidence->update([
            'verification_status' => $data['status'],
            'verified_at'         => now(),
            'verified_by'         => $request->user()->id,
            'notes'               => $data['notes'] ?? $evidence->notes,
        ]);

        return response()->json($evidence->fresh());
    }
}
