<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 94 — Linked Art JSON-LD endpoint.
 *
 * Returns artwork metadata as Linked Art (https://linked.art) JSON-LD,
 * the CIDOC-CRM–based standard used by major art institutions.
 * Content-Type: application/ld+json
 */
final class LinkedArtController extends Controller
{
    /**
     * GET /api/v1/artworks/{artwork}/linked-art
     */
    public function show(Request $request, Artwork $artwork): JsonResponse
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        $artwork->load('artist:id,name');

        $baseUrl = rtrim(config('app.url'), '/');
        $id      = "{$baseUrl}/api/v1/artworks/{$artwork->id}/linked-art";

        $document = [
            '@context'   => 'https://linked.art/ns/v1/linked-art.json',
            'id'         => $id,
            'type'       => 'HumanMadeObject',
            '_label'     => $artwork->title,

            // Identified by title
            'identified_by' => [
                [
                    'type'    => 'Name',
                    'content' => $artwork->title,
                    'classified_as' => [
                        ['id' => 'http://vocab.getty.edu/aat/300404670', 'type' => 'Type', '_label' => 'Primary Name'],
                    ],
                ],
            ],

            // Creator
            'produced_by' => $artwork->artist ? [
                'type'    => 'Production',
                'carried_out_by' => [
                    [
                        'type'   => 'Person',
                        '_label' => $artwork->artist->name,
                    ],
                ],
            ] : null,

            // Medium as material
            'made_of' => $artwork->medium ? [
                [
                    'type'   => 'Material',
                    '_label' => $artwork->medium,
                ],
            ] : [],

            // Creation date
            'timespan' => $artwork->year_created ? [
                'type'               => 'TimeSpan',
                'begin_of_the_begin' => "{$artwork->year_created}-01-01T00:00:00Z",
                'end_of_the_end'     => "{$artwork->year_created}-12-31T23:59:59Z",
                '_label'             => (string) $artwork->year_created,
            ] : null,

            // Description
            'referred_to_by' => $artwork->description ? [
                [
                    'type'    => 'LinguisticObject',
                    'content' => $artwork->description,
                    'classified_as' => [
                        ['id' => 'http://vocab.getty.edu/aat/300080091', 'type' => 'Type', '_label' => 'Description'],
                    ],
                ],
            ] : [],

            // Digital representation
            'representation' => $artwork->primary_image_key ? [
                [
                    'type'    => 'VisualItem',
                    'digitally_shown_by' => [
                        [
                            'type'          => 'DigitalObject',
                            'access_point'  => [
                                ['id' => "{$baseUrl}/api/v1/artworks/{$artwork->id}/image", 'type' => 'DigitalObject'],
                            ],
                            'format'        => 'image/webp',
                        ],
                    ],
                ],
            ] : [],
        ];

        // Remove null values at top level
        $document = array_filter($document, fn ($v) => $v !== null);

        return response()
            ->json($document, 200, ['Content-Type' => 'application/ld+json'])
            ->header('Content-Type', 'application/ld+json');
    }
}
