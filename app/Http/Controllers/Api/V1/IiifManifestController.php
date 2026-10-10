<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;

/**
 * Phase 128 — IIIF Presentation API 3.0 Manifest endpoint.
 *
 * Returns a minimal IIIF Manifest for an artwork, suitable for use with
 * any IIIF-compatible viewer (Universal Viewer, Mirador, etc.).
 * Content-Type: application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"
 *
 * @tags Cultural Interoperability
 */
final class IiifManifestController extends Controller
{
    /**
     * GET /api/v1/artworks/{artwork}/iiif/manifest
     *
     * @unauthenticated
     * @response array
     */
    public function show(Artwork $artwork): JsonResponse
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        $artwork->load('artist:id,name');

        $baseUrl     = rtrim(config('app.url'), '/');
        $manifestId  = "{$baseUrl}/api/v1/artworks/{$artwork->slug}/iiif/manifest";
        $canvasId    = "{$manifestId}/canvas/1";
        $annoPageId  = "{$canvasId}/page/1";
        $annoId      = "{$annoPageId}/anno/1";

        $imageUrl = $artwork->primary_image_key
            ? "{$baseUrl}/api/v1/artworks/{$artwork->id}/image"
            : null;

        $items = [];
        if ($imageUrl) {
            $items = [
                [
                    'id'     => $canvasId,
                    'type'   => 'Canvas',
                    'label'  => ['en' => [$artwork->title]],
                    'width'  => 1000,
                    'height' => 1000,
                    'items'  => [
                        [
                            'id'   => $annoPageId,
                            'type' => 'AnnotationPage',
                            'items' => [
                                [
                                    'id'         => $annoId,
                                    'type'       => 'Annotation',
                                    'motivation' => 'painting',
                                    'body'       => [
                                        'id'     => $imageUrl,
                                        'type'   => 'Image',
                                        'format' => 'image/webp',
                                    ],
                                    'target' => $canvasId,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id'       => $manifestId,
            'type'     => 'Manifest',
            'label'    => ['en' => [$artwork->title]],

            'metadata' => array_filter([
                $artwork->artist ? ['label' => ['en' => ['Creator']], 'value' => ['en' => [$artwork->artist->name]]] : null,
                $artwork->medium ? ['label' => ['en' => ['Medium']],  'value' => ['en' => [$artwork->medium]]]       : null,
                $artwork->year_created ? ['label' => ['en' => ['Date']], 'value' => ['en' => [(string) $artwork->year_created]]] : null,
            ]),

            'summary' => $artwork->description
                ? ['en' => [$artwork->description]]
                : null,

            'rights' => $artwork->license_spdx ?? null,

            'items' => $items,
        ];

        // Remove null top-level keys
        $manifest = array_filter($manifest, fn ($v) => $v !== null);

        $contentType = 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"';

        return response()
            ->json($manifest, 200)
            ->header('Content-Type', $contentType);
    }
}
