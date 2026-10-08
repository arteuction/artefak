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

    /** POST /api/v1/impact-projects */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['required', 'string', 'max:200'],
            'slug'                 => ['required', 'string', 'max:200', 'unique:impact_projects,slug'],
            'description'          => ['nullable', 'string'],
            'sdg_number'           => ['required', 'integer', 'min:1', 'max:17'],
            'funding_target_cents' => ['nullable', 'integer', 'min:0'],
            'status'               => ['nullable', 'in:planned,active,completed,paused'],
            'starts_on'            => ['nullable', 'date'],
            'ends_on'              => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $project = ImpactProject::create($data);

        return response()->json($project, 201);
    }

    /** GET /api/v1/impact-projects/{impactProject}/evidence */
    public function indexEvidence(ImpactProject $impactProject): JsonResponse
    {
        $evidence = Evidence::where('impact_project_id', $impactProject->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($evidence);
    }

    /** PATCH /api/v1/impact-projects/{impactProject} */
    public function update(Request $request, ImpactProject $impactProject): JsonResponse
    {
        $data = $request->validate([
            'title'                => ['sometimes', 'string', 'max:200'],
            'description'          => ['sometimes', 'nullable', 'string'],
            'sdg_number'           => ['sometimes', 'integer', 'min:1', 'max:17'],
            'funding_target_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'               => ['sometimes', 'in:planned,active,completed,paused'],
            'starts_on'            => ['sometimes', 'nullable', 'date'],
            'ends_on'              => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $impactProject->update($data);

        return response()->json($impactProject->fresh());
    }

    /**
     * POST /api/v1/impact-projects/{impactProject}/evidence
     *
     * Attach a new evidence record to this project.
     * Evidence is polymorphic — subject is the ImpactProject itself.
     */
    public function storeEvidence(Request $request, ImpactProject $impactProject): JsonResponse
    {
        $data = $request->validate([
            'type'          => ['required', 'in:authenticity,provenance,condition,payment,donation,ownership,delivery,impact'],
            'subtype'       => ['nullable', 'string', 'max:60'],
            'document_path' => ['nullable', 'string', 'max:500'],
            'document_mime' => ['nullable', 'string', 'max:100'],
            'issuer'        => ['nullable', 'string', 'max:200'],
            'issued_at'     => ['nullable', 'date'],
            'notes'         => ['nullable', 'string'],
        ]);

        $evidence = Evidence::create([
            ...$data,
            'subject_type'      => $impactProject->getMorphClass(),
            'subject_id'        => $impactProject->id,
            'impact_project_id' => $impactProject->id,
            'verification_status' => 'pending',
        ]);

        return response()->json($evidence, 201);
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
