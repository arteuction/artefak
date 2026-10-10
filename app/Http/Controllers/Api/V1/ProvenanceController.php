<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Provenance\BuildProvenanceGraph;
use App\Models\ArtLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Provenance
 */
final class ProvenanceController extends Controller
{
    public function __construct(private BuildProvenanceGraph $builder) {}

    /**
     * W3C PROV-O provenance graph for an ArtLot.
     *
     * Returns a JSON-LD document describing the full lifecycle of the lot:
     * the artwork entity, each domain event as a prov:Activity, and every
     * participating prov:Agent (buyer, seller, gallery).
     *
     * @unauthenticated
     * @response array{@context: array, @graph: array}
     */
    public function show(Request $request, ArtLot $artLot): JsonResponse
    {
        $graph = $this->builder->execute($artLot);

        return response()->json($graph)
            ->header('Content-Type', 'application/ld+json');
    }
}
