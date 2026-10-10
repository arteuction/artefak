<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use Illuminate\Http\JsonResponse;

/**
 * Phase 97 — IIIF Image API 3.0 info.json endpoint.
 *
 * Exposes artwork primary images as IIIF resources so they can be used
 * in institutional viewers (Universal Viewer, Mirador) and catalogues.
 *
 * Only the info.json descriptor is served here; actual image tiles are
 * served by S3 / CloudFront. The endpoint returns 404 for artworks with
 * no confirmed primary image and for non-listed artworks.
 */
final class IiifController extends Controller
{
    /**
     * GET /api/v1/artworks/{artwork}/iiif/info.json
     *
     * Returns an IIIF Image API 3.0 info.json descriptor.
     * Content-Type: application/ld+json;profile="http://iiif.io/api/image/3/context.json"
     */
    public function info(Artwork $artwork): JsonResponse
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        if (! $artwork->primary_image_key || $artwork->primary_image_status !== 'confirmed') {
            abort(404);
        }

        $base     = rtrim(config('app.url'), '/');
        $id       = "{$base}/api/v1/artworks/{$artwork->slug}/iiif";
        $imageUrl = $this->resolveImageUrl($artwork->primary_image_key);

        $derivatives = $artwork->image_derivatives ?? [];
        $width  = $derivatives['original']['width']  ?? null;
        $height = $derivatives['original']['height'] ?? null;

        $info = [
            '@context' => 'http://iiif.io/api/image/3/context.json',
            'id'       => $id,
            'type'     => 'ImageService3',
            'protocol' => 'http://iiif.io/api/image',
            'profile'  => 'level1',

            // Sizes hint — viewers use these to pick tile requests
            'sizes' => array_values(array_filter([
                $width && $height ? ['width' => $width, 'height' => $height] : null,
                isset($derivatives['large'])   ? ['width' => $derivatives['large']['width'],   'height' => $derivatives['large']['height']]   : null,
                isset($derivatives['medium'])  ? ['width' => $derivatives['medium']['width'],  'height' => $derivatives['medium']['height']]  : null,
                isset($derivatives['thumb'])   ? ['width' => $derivatives['thumb']['width'],   'height' => $derivatives['thumb']['height']]   : null,
            ])),

            // Preferred formats
            'preferredFormats' => ['webp', 'jpeg'],

            // Rights / attribution
            'rights' => 'https://creativecommons.org/licenses/by-nc/4.0/',

            // Thumbnail for viewers that need a quick preview
            'thumbnail' => $imageUrl ? [
                [
                    'id'   => $imageUrl,
                    'type' => 'Image',
                ],
            ] : [],
        ];

        return response()
            ->json($info, 200)
            ->header('Content-Type', 'application/ld+json;profile="http://iiif.io/api/image/3/context.json"');
    }

    /**
     * GET /api/v1/artworks/{artwork}/iiif/manifest
     *
     * Returns an IIIF Presentation API 3.0 manifest for the artwork.
     */
    public function manifest(Artwork $artwork): JsonResponse
    {
        if ($artwork->status !== 'listed') {
            abort(404);
        }

        $artwork->load('artist:id,name');

        $base     = rtrim(config('app.url'), '/');
        $id       = "{$base}/api/v1/artworks/{$artwork->slug}/iiif/manifest";
        $imageUrl = $artwork->primary_image_key
            ? $this->resolveImageUrl($artwork->primary_image_key)
            : null;

        $manifest = [
            '@context'  => 'http://iiif.io/api/presentation/3/context.json',
            'id'        => $id,
            'type'      => 'Manifest',
            'label'     => ['en' => [$artwork->title]],
            'summary'   => $artwork->description ? ['en' => [$artwork->description]] : null,
            'requiredStatement' => $artwork->artist ? [
                'label' => ['en' => ['Attribution']],
                'value' => ['en' => [$artwork->artist->name]],
            ] : null,
            'items' => [
                [
                    'id'    => "{$base}/api/v1/artworks/{$artwork->slug}/iiif/canvas/1",
                    'type'  => 'Canvas',
                    'label' => ['en' => [$artwork->title]],
                    'items' => $imageUrl ? [
                        [
                            'id'    => "{$base}/api/v1/artworks/{$artwork->slug}/iiif/annotationpage/1",
                            'type'  => 'AnnotationPage',
                            'items' => [
                                [
                                    'id'         => "{$base}/api/v1/artworks/{$artwork->slug}/iiif/annotation/1",
                                    'type'       => 'Annotation',
                                    'motivation' => 'painting',
                                    'body'       => [
                                        'id'     => $imageUrl,
                                        'type'   => 'Image',
                                        'format' => 'image/webp',
                                        'service' => [
                                            [
                                                '@context' => 'http://iiif.io/api/image/3/context.json',
                                                'id'       => "{$base}/api/v1/artworks/{$artwork->slug}/iiif",
                                                'type'     => 'ImageService3',
                                                'profile'  => 'level1',
                                            ],
                                        ],
                                    ],
                                    'target' => "{$base}/api/v1/artworks/{$artwork->slug}/iiif/canvas/1",
                                ],
                            ],
                        ],
                    ] : [],
                ],
            ],
        ];

        return response()
            ->json($manifest, 200)
            ->header('Content-Type', 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"');
    }

    private function resolveImageUrl(?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        $cdnBase = config('filesystems.disks.s3.url') ?? config('app.url');
        return rtrim($cdnBase, '/') . '/' . ltrim($key, '/');
    }
}
