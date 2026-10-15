<?php

declare(strict_types=1);

namespace Tests\Feature\Preservation;

use App\Domain\Preservation\BuildPremisRecord;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 180 — PREMIS 3.0 preservation metadata record builder.
 *
 * Verifies BuildPremisRecord produces a correct PREMIS JSON-LD structure
 * for artwork preservation records per loc.gov PREMIS 3.0 spec.
 */
final class BuildPremisRecordTest extends TestCase
{
    use RefreshDatabase;

    private BuildPremisRecord $builder;
    private User $artist;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(BuildPremisRecord::class);
        $this->artist  = User::factory()->create(['role' => 'artist']);
        $this->artwork = Artwork::create([
            'user_id'      => $this->artist->id,
            'title'        => 'Sunrise Over Sofia',
            'slug'         => 'sunrise-over-sofia',
            'status'       => 'listed',
            'medium'       => 'painting',
            'license_spdx' => 'CC-BY-4.0',
        ]);
    }

    // ── Top-level structure ────────────────────────────────────────────────────

    public function test_output_has_premis_context(): void
    {
        $record = $this->builder->execute($this->artwork);

        $this->assertSame(
            'https://id.loc.gov/ontologies/premis-3-0-0.jsonld',
            $record['@context'],
        );
    }

    public function test_output_type_is_premis_object(): void
    {
        $record = $this->builder->execute($this->artwork);

        $this->assertSame('premis:Object', $record['@type']);
    }

    // ── Object identifier ──────────────────────────────────────────────────────

    public function test_object_identifier_type_is_arteuction_artwork_id(): void
    {
        $record = $this->builder->execute($this->artwork);

        $this->assertSame(
            'ARTeuCtion-artwork-id',
            $record['premis:objectIdentifier']['premis:objectIdentifierType'],
        );
    }

    public function test_object_identifier_value_is_artwork_id_string(): void
    {
        $record = $this->builder->execute($this->artwork);

        $this->assertSame(
            (string) $this->artwork->id,
            $record['premis:objectIdentifier']['premis:objectIdentifierValue'],
        );
    }

    // ── Fixity ─────────────────────────────────────────────────────────────────

    public function test_fixity_algorithm_is_sha256(): void
    {
        $record = $this->builder->execute($this->artwork);
        $fixity = $record['premis:objectCharacteristics']['premis:fixity'];

        $this->assertSame('SHA-256', $fixity['premis:messageDigestAlgorithm']);
    }

    public function test_fixity_digest_is_sha256_of_image_key(): void
    {
        $this->artwork->primary_image_key = 'artworks/sunrise.jpg';
        $this->artwork->save();

        $record = $this->builder->execute($this->artwork->fresh());
        $fixity = $record['premis:objectCharacteristics']['premis:fixity'];

        $this->assertSame(
            hash('sha256', 'artworks/sunrise.jpg'),
            $fixity['premis:messageDigest'],
        );
    }

    public function test_fixity_digest_is_empty_string_when_no_image(): void
    {
        $record = $this->builder->execute($this->artwork);
        $fixity = $record['premis:objectCharacteristics']['premis:fixity'];

        $this->assertSame('', $fixity['premis:messageDigest']);
    }

    public function test_fixity_originator_is_arteuction_ingest(): void
    {
        $record = $this->builder->execute($this->artwork);
        $fixity = $record['premis:objectCharacteristics']['premis:fixity'];

        $this->assertSame('ARTeuCtion-ingest', $fixity['premis:messageDigestOriginator']);
    }

    // ── Significant properties ─────────────────────────────────────────────────

    public function test_significant_properties_contain_title(): void
    {
        $record     = $this->builder->execute($this->artwork);
        $properties = $record['premis:objectCharacteristics']['premis:significantProperties'];

        $titles = array_column($properties, 'premis:significantPropertiesValue',
                               'premis:significantPropertiesType');

        $this->assertSame('Sunrise Over Sofia', $titles['title']);
    }

    public function test_significant_properties_contain_creator_user_id(): void
    {
        $record     = $this->builder->execute($this->artwork);
        $properties = $record['premis:objectCharacteristics']['premis:significantProperties'];

        $props = array_column($properties, 'premis:significantPropertiesValue',
                              'premis:significantPropertiesType');

        $this->assertSame((string) $this->artist->id, $props['creator']);
    }

    // ── OCFL storage path ──────────────────────────────────────────────────────

    public function test_storage_content_location_type_is_ocfl(): void
    {
        $record  = $this->builder->execute($this->artwork);
        $storage = $record['premis:storage']['premis:contentLocation'];

        $this->assertSame('OCFL', $storage['premis:contentLocationType']);
    }

    public function test_storage_path_starts_with_preservation(): void
    {
        $record = $this->builder->execute($this->artwork);
        $path   = $record['premis:storage']['premis:contentLocation']['premis:contentLocationValue'];

        $this->assertStringStartsWith('/preservation/', $path);
    }

    public function test_storage_path_has_3_pair_pairtree_structure(): void
    {
        $record = $this->builder->execute($this->artwork);
        $path   = $record['premis:storage']['premis:contentLocation']['premis:contentLocationValue'];

        // Should be: /preservation/XX/XX/XX/<sha256>/
        $this->assertMatchesRegularExpression(
            '#^/preservation/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}/$#',
            $path,
        );
    }

    // ── Rights statement ───────────────────────────────────────────────────────

    public function test_rights_statement_type_is_spdx(): void
    {
        $record = $this->builder->execute($this->artwork);
        $rights = $record['premis:linkingRightsStatementIdentifier'];

        $this->assertSame('SPDX', $rights['premis:linkingRightsStatementIdentifierType']);
    }

    public function test_rights_statement_value_is_license_spdx(): void
    {
        $record = $this->builder->execute($this->artwork);
        $rights = $record['premis:linkingRightsStatementIdentifier'];

        $this->assertSame('CC-BY-4.0', $rights['premis:linkingRightsStatementIdentifierValue']);
    }

    public function test_rights_statement_falls_back_to_licenseref_unknown(): void
    {
        $artwork = Artwork::create([
            'user_id'      => $this->artist->id,
            'title'        => 'No License',
            'slug'         => 'no-license',
            'status'       => 'draft',
            'license_spdx' => null,
        ]);

        $record = $this->builder->execute($artwork);
        $rights = $record['premis:linkingRightsStatementIdentifier'];

        $this->assertSame('LicenseRef-unknown', $rights['premis:linkingRightsStatementIdentifierValue']);
    }

    // ── Bulgarian Cyrillic title ───────────────────────────────────────────────

    public function test_cyrillic_title_preserved_in_significant_properties(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Изгрев над София',
            'slug'    => 'izgrev-nad-sofia',
            'status'  => 'draft',
        ]);

        $record     = $this->builder->execute($artwork);
        $properties = $record['premis:objectCharacteristics']['premis:significantProperties'];
        $props      = array_column($properties, 'premis:significantPropertiesValue',
                                   'premis:significantPropertiesType');

        $this->assertSame('Изгрев над София', $props['title']);
    }
}
