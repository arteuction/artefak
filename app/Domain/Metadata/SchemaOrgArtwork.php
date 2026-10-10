<?php

declare(strict_types=1);

namespace App\Domain\Metadata;

use App\Models\Artwork;

/**
 * Phase 99 — Schema.org/VisualArtwork structured data builder.
 *
 * Generates JSON-LD markup for search engine rich results.
 * Spec: https://schema.org/VisualArtwork
 */
final class SchemaOrgArtwork
{
    public function build(Artwork $artwork): array
    {
        $base = rtrim(config('app.url'), '/');

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'VisualArtwork',
            'name'     => $artwork->title,
            'url'      => "{$base}/artworks/{$artwork->id}",
        ];

        if ($artwork->description) {
            $schema['description'] = $artwork->description;
        }

        if ($artwork->medium) {
            $schema['artMedium'] = $artwork->medium;
        }

        if ($artwork->year_created) {
            $schema['dateCreated'] = (string) $artwork->year_created;
        }

        if ($artwork->artist) {
            $schema['creator'] = [
                '@type' => 'Person',
                'name'  => $artwork->artist->name,
            ];
        }

        if ($artwork->primary_image_key && $artwork->primary_image_status === 'confirmed') {
            $cdnBase = config('filesystems.disks.s3.url') ?? $base;
            $schema['image'] = rtrim($cdnBase, '/') . '/' . ltrim($artwork->primary_image_key, '/');
        }

        // IIIF links as sameAs / additionalProperty for harvesters
        $schema['sameAs'] = [
            "{$base}/api/v1/artworks/{$artwork->id}/linked-art",
        ];

        $schema['additionalProperty'] = [
            [
                '@type' => 'PropertyValue',
                'name'  => 'iiifManifest',
                'value' => "{$base}/api/v1/artworks/{$artwork->id}/iiif/manifest",
            ],
        ];

        // SDG claims as keywords
        $approvedSdgs = $artwork->sdgClaims
            ->where('status', 'approved')
            ->pluck('sdg_number')
            ->map(fn ($n) => "UN SDG {$n}")
            ->values()
            ->all();

        if (! empty($approvedSdgs)) {
            $schema['keywords'] = implode(', ', $approvedSdgs);
        }

        if ($artwork->is_original) {
            $schema['artEdition'] = 'original';
        } elseif ($artwork->edition_total) {
            $schema['artEdition'] = "edition of {$artwork->edition_total}";
        }

        return $schema;
    }
}
