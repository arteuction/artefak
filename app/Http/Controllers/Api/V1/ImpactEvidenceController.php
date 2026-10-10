<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ImpactEvidence;
use App\Models\ImpactEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Impact Evidence
 */
final class ImpactEvidenceController extends Controller
{
    /**
     * List evidence attachments for an impact event.
     *
     * @unauthenticated
     * @response array{data: ImpactEvidence[]}
     */
    public function index(ImpactEvent $impactEvent): JsonResponse
    {
        return response()->json([
            'data' => $impactEvent->evidence()->orderBy('created_at')->get(),
        ]);
    }

    /**
     * Attach evidence to an impact event.
     *
     * Only admins may attach evidence; end-users submit evidence via the
     * impact claim flow which gets admin-reviewed before attachment.
     *
     * @response 201 ImpactEvidence
     */
    public function store(Request $request, ImpactEvent $impactEvent): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'type'        => ['required', 'in:report,photo,certification,url,other'],
            'url'         => ['required', 'string', 'max:1024'],
            'description' => ['nullable', 'string', 'max:512'],
        ]);

        $evidence = ImpactEvidence::create([
            'impact_event_id' => $impactEvent->id,
            'uploaded_by'     => $request->user()->id,
            'type'            => $data['type'],
            'url'             => $data['url'],
            'description'     => $data['description'] ?? null,
        ]);

        return response()->json(['data' => $evidence], 201);
    }
}
