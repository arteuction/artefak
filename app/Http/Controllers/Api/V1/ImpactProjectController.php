<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Evidence;
use App\Models\ImpactProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ImpactProjectController extends Controller
{
    /** GET /api/v1/impact-projects */
    public function index(Request $request): JsonResponse
    {
        $query = ImpactProject::withCount(['evidence', 'activePartners']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('sdg')) {
            $query->where('sdg_number', (int) $request->input('sdg'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    /** GET /api/v1/impact-projects/{impactProject} */
    public function show(ImpactProject $impactProject): JsonResponse
    {
        $impactProject->load(['evidence', 'activePartners.organization:id,name']);

        return response()->json($impactProject);
    }

    /**
     * GET /api/v1/impact-projects/stats
     *
     * Public aggregate stats for the impact landing page — no donor identity exposed.
     */
    public function stats(): JsonResponse
    {
        $projects = ImpactProject::selectRaw('
            COUNT(*) as total_projects,
            SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_projects,
            SUM(funding_actual_cents) as total_funded_cents,
            SUM(funding_target_cents) as total_target_cents
        ')->first();

        $donorCount = Donation::where('status', 'confirmed')
            ->distinct('donor_id')
            ->count('donor_id');

        $evidenceCount = Evidence::where('verified_at', '!=', null)->count();

        $beneficiaries = DB::table('impact_events')
            ->where('type', 'event')
            ->where('metric', 'beneficiaries_reached')
            ->sum('magnitude');

        return response()->json([
            'total_projects'       => (int) $projects->total_projects,
            'active_projects'      => (int) $projects->active_projects,
            'total_funded_cents'   => (int) $projects->total_funded_cents,
            'total_target_cents'   => (int) $projects->total_target_cents,
            'total_donors'         => $donorCount,
            'verified_evidence'    => $evidenceCount,
            'total_beneficiaries'  => (int) $beneficiaries,
        ]);
    }
}
