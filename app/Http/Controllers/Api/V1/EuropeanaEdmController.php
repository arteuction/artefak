<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;

/**
 * Phase 128 — Europeana EDM (Europeana Data Model) JSON-LD endpoint.
 *
 * Returns artwork metadata as an EDM ProvidedCHO record compatible with
 * the Europeana Aggregation API.
 * Content-Type: application/ld+json
 *
 * @tags Cultural Interoperability
 */
final class EuropeanaEdmController extends Controller
{
    private const EDM_CONTEXT = [
        'edm'  => 'http://www.europeana.eu/schemas/edm/',
        'ore'  => 'http://www.openarchives.org/ore/terms/',
        'dc'   => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'rdaGr2' => 'http://rdvocab.info/ElementsGr2/',
    ];

    /**
     * GET /api/v1/artworks/{artwork}/edm
     *
     * @unauthenticated
     * @response array{@context: array, @graph: array}
     */
    public function show(Artwork $artwork): JsonResponse
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        $artwork->load('artist:id,name');

        $baseUrl = rtrim(config('app.url'), '/');
        $choId   = "{$baseUrl}/api/v1/artworks/{$artwork->id}/edm#cho";
        $aggId   = "{$baseUrl}/api/v1/artworks/{$artwork->id}/edm#agg";
        $webId   = "{$baseUrl}/api/v1/artworks/{$artwork->id}/edm#web";

        $graph = [];

        // Cultural Heritage Object
        $cho = [
            '@id'    => $choId,
            '@type'  => 'edm:ProvidedCHO',
            'dc:title'   => $artwork->title,
            'dc:type'    => 'PhysicalObject',
        ];

        if ($artwork->description) {
            $cho['dc:description'] = $artwork->description;
        }
        if ($artwork->medium) {
            $cho['dc:medium'] = $artwork->medium;
        }
        if ($artwork->year_created) {
            $cho['dcterms:created'] = (string) $artwork->year_created;
        }
        if ($artwork->artist) {
            $cho['dc:creator'] = $artwork->artist->name;
        }
        if ($artwork->license_spdx) {
            $cho['dcterms:rights'] = $artwork->license_spdx;
        }
        if ($artwork->rights_statement) {
            $cho['edm:rights'] = ['@id' => $artwork->rights_statement];
        }

        $graph[] = $cho;

        // Aggregation
        $agg = [
            '@id'    => $aggId,
            '@type'  => 'ore:Aggregation',
            'edm:aggregatedCHO' => ['@id' => $choId],
            'edm:dataProvider'  => 'ARTeuCtion',
            'edm:provider'      => 'ARTeuCtion',
            'edm:rights'        => ['@id' => 'http://creativecommons.org/licenses/by/4.0/'],
        ];

        if ($artwork->primary_image_key) {
            $agg['edm:isShownBy'] = ['@id' => $webId];
        }

        $graph[] = $agg;

        // Web resource (image)
        if ($artwork->primary_image_key) {
            $graph[] = [
                '@id'    => $webId,
                '@type'  => 'edm:WebResource',
                'dc:format'     => 'image/webp',
                'edm:rights'    => ['@id' => 'http://creativecommons.org/licenses/by/4.0/'],
            ];
        }

        return response()
            ->json(['@context' => self::EDM_CONTEXT, '@graph' => $graph], 200)
            ->header('Content-Type', 'application/ld+json');
    }
}
