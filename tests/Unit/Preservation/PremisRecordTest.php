<?php

declare(strict_types=1);

namespace Tests\Unit\Preservation;

use App\Domain\Preservation\BuildPremisRecord;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 147 — PREMIS / OCFL preservation metadata.
 */
class PremisRecordTest extends TestCase
{
    use RefreshDatabase;

    private BuildPremisRecord $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new BuildPremisRecord();
    }

    public function test_record_has_premis_context(): void
    {
        $artwork = $this->makeArtwork();
        $record  = $this->builder->execute($artwork);

        $this->assertStringContainsString('premis', $record['@context']);
        $this->assertSame('premis:Object', $record['@type']);
    }

    public function test_object_identifier_type_is_arteuction(): void
    {
        $artwork = $this->makeArtwork();
        $record  = $this->builder->execute($artwork);

        $id = $record['premis:objectIdentifier'];
        $this->assertSame('ARTeuCtion-artwork-id', $id['premis:objectIdentifierType']);
        $this->assertSame((string) $artwork->id, $id['premis:objectIdentifierValue']);
    }

    public function test_fixity_uses_sha256(): void
    {
        $artwork = $this->makeArtwork(['primary_image_key' => 'https://cdn.example.com/img.jpg']);
        $record  = $this->builder->execute($artwork);

        $fixity = $record['premis:objectCharacteristics']['premis:fixity'];
        $this->assertSame('SHA-256', $fixity['premis:messageDigestAlgorithm']);
        $this->assertSame(hash('sha256', 'https://cdn.example.com/img.jpg'), $fixity['premis:messageDigest']);
    }

    public function test_fixity_digest_empty_when_no_image(): void
    {
        $artwork = $this->makeArtwork(['primary_image_key' => null]);
        $record  = $this->builder->execute($artwork);

        $fixity = $record['premis:objectCharacteristics']['premis:fixity'];
        $this->assertSame('', $fixity['premis:messageDigest']);
    }

    public function test_storage_location_type_is_ocfl(): void
    {
        $artwork = $this->makeArtwork();
        $record  = $this->builder->execute($artwork);

        $location = $record['premis:storage']['premis:contentLocation'];
        $this->assertSame('OCFL', $location['premis:contentLocationType']);
    }

    public function test_ocfl_path_follows_pairtree_convention(): void
    {
        $artwork  = $this->makeArtwork();
        $record   = $this->builder->execute($artwork);
        $path     = $record['premis:storage']['premis:contentLocation']['premis:contentLocationValue'];

        // /preservation/{2}/{2}/{2}/{64}/
        $this->assertMatchesRegularExpression(
            '#^/preservation/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}/$#',
            $path,
        );
    }

    public function test_ocfl_paths_differ_for_different_artworks(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artworkA = Artwork::create(['user_id' => $user->id, 'title' => 'A', 'slug' => 'a-' . uniqid(), 'status' => 'listed']);
        $artworkB = Artwork::create(['user_id' => $user->id, 'title' => 'B', 'slug' => 'b-' . uniqid(), 'status' => 'listed']);

        $recordA = $this->builder->execute($artworkA);
        $recordB = $this->builder->execute($artworkB);

        $pathA = $recordA['premis:storage']['premis:contentLocation']['premis:contentLocationValue'];
        $pathB = $recordB['premis:storage']['premis:contentLocation']['premis:contentLocationValue'];

        $this->assertNotSame($pathA, $pathB);
    }

    public function test_rights_statement_uses_license_spdx(): void
    {
        $artwork = $this->makeArtwork(['license_spdx' => 'CC-BY-4.0']);
        $record  = $this->builder->execute($artwork);

        $rights = $record['premis:linkingRightsStatementIdentifier'];
        $this->assertSame('SPDX', $rights['premis:linkingRightsStatementIdentifierType']);
        $this->assertSame('CC-BY-4.0', $rights['premis:linkingRightsStatementIdentifierValue']);
    }

    public function test_rights_statement_defaults_to_unknown_when_no_spdx(): void
    {
        $artwork = $this->makeArtwork(['license_spdx' => null]);
        $record  = $this->builder->execute($artwork);

        $this->assertSame('LicenseRef-unknown',
            $record['premis:linkingRightsStatementIdentifier']['premis:linkingRightsStatementIdentifierValue']);
    }

    public function test_significant_properties_include_title_and_creator(): void
    {
        $artwork = $this->makeArtwork(['title' => 'Test Title']);
        $record  = $this->builder->execute($artwork);

        $props = $record['premis:objectCharacteristics']['premis:significantProperties'];
        $types = array_column($props, 'premis:significantPropertiesType');

        $this->assertContains('title', $types);
        $this->assertContains('creator', $types);
    }

    public function test_record_is_deterministic_for_same_artwork(): void
    {
        $artwork = $this->makeArtwork();

        $recordA = $this->builder->execute($artwork);
        $recordB = $this->builder->execute($artwork);

        $this->assertSame(
            $recordA['premis:storage']['premis:contentLocation']['premis:contentLocationValue'],
            $recordB['premis:storage']['premis:contentLocation']['premis:contentLocationValue'],
        );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeArtwork(array $overrides = []): Artwork
    {
        $user = User::factory()->create(['role' => 'artist']);

        return Artwork::create(array_merge([
            'user_id' => $user->id,
            'title'   => 'Preservation Test Artwork',
            'slug'    => 'preservation-' . uniqid(),
            'status'  => 'listed',
        ], $overrides));
    }
}
