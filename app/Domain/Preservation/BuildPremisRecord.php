<?php

declare(strict_types=1);

namespace App\Domain\Preservation;

use App\Models\Artwork;

/**
 * Phase 147 — Digital-asset preservation.
 *
 * Builds a PREMIS 3.0-compatible preservation metadata record for an artwork.
 * PREMIS (Preservation Metadata: Implementation Strategies) is the de-facto
 * standard for digital preservation in cultural heritage institutions.
 *
 * Output is a plain PHP array that serialises to JSON-LD or XML.
 *
 * OCFL (Oxford Common File Layout) object path convention:
 *   /preservation/{sha256[0..2]}/{sha256[2..4]}/{sha256[4..6]}/{sha256}/
 *
 * @see https://www.loc.gov/standards/premis/
 * @see https://ocfl.io/
 */
final class BuildPremisRecord
{
    public function execute(Artwork $artwork): array
    {
        $objectId    = $this->objectIdentifier($artwork);
        $fixity      = $this->fixityBlock($artwork);
        $ocflPath    = $this->ocflObjectPath($objectId);
        $significant = $this->significantProperties($artwork);

        return [
            '@context'        => 'https://id.loc.gov/ontologies/premis-3-0-0.jsonld',
            '@type'           => 'premis:Object',
            'premis:objectIdentifier' => [
                'premis:objectIdentifierType'  => 'ARTeuCtion-artwork-id',
                'premis:objectIdentifierValue' => (string) $artwork->id,
            ],
            'premis:objectCharacteristics' => [
                'premis:fixity'          => $fixity,
                'premis:significantProperties' => $significant,
            ],
            'premis:storage' => [
                'premis:contentLocation' => [
                    'premis:contentLocationType'  => 'OCFL',
                    'premis:contentLocationValue' => $ocflPath,
                ],
            ],
            'premis:linkingRightsStatementIdentifier' => [
                'premis:linkingRightsStatementIdentifierType'  => 'SPDX',
                'premis:linkingRightsStatementIdentifierValue' => $artwork->license_spdx ?? 'LicenseRef-unknown',
            ],
            'pav:createdOn' => optional($artwork->created_at)->toIso8601String(),
            'pav:lastUpdatedOn' => optional($artwork->updated_at)->toIso8601String(),
        ];
    }

    private function objectIdentifier(Artwork $artwork): string
    {
        return hash('sha256', "arteuction:artwork:{$artwork->id}");
    }

    private function fixityBlock(Artwork $artwork): array
    {
        $imageUrl = $artwork->image_url ?? '';

        return [
            'premis:messageDigestAlgorithm' => 'SHA-256',
            'premis:messageDigest'          => $imageUrl !== ''
                ? hash('sha256', $imageUrl)
                : '',
            'premis:messageDigestOriginator' => 'ARTeuCtion-ingest',
        ];
    }

    private function significantProperties(Artwork $artwork): array
    {
        return [
            [
                'premis:significantPropertiesType'  => 'title',
                'premis:significantPropertiesValue' => $artwork->title,
            ],
            [
                'premis:significantPropertiesType'  => 'creator',
                'premis:significantPropertiesValue' => (string) $artwork->user_id,
            ],
        ];
    }

    /**
     * OCFL-style 3-pair pairtree path from SHA-256 object identifier.
     */
    private function ocflObjectPath(string $sha256): string
    {
        return sprintf(
            '/preservation/%s/%s/%s/%s/',
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            substr($sha256, 4, 2),
            $sha256,
        );
    }
}
