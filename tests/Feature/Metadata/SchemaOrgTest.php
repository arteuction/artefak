<?php

declare(strict_types=1);

namespace Tests\Feature\Metadata;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 99 — Schema.org/VisualArtwork structured metadata.
 */
final class SchemaOrgTest extends TestCase
{
    use RefreshDatabase;

    private function listedArtwork(array $override = []): Artwork
    {
        $user = User::factory()->create(['role' => 'artist']);
        return Artwork::create(array_merge([
            'user_id'      => $user->id,
            'title'        => 'Schema Test Work',
            'slug'         => Str::uuid()->toString(),
            'status'       => 'listed',
            'medium'       => 'painting',
            'description'  => 'A beautiful painting.',
            'year_created' => 2010,
            'is_original'  => true,
        ], $override));
    }

    public function test_artwork_show_includes_schema_org_key(): void
    {
        $artwork = $this->listedArtwork();

        $this->getJson("/api/v1/search/artworks/{$artwork->id}")
            ->assertOk()
            ->assertJsonStructure(['data', 'schema_org']);
    }

    public function test_schema_org_type_is_visual_artwork(): void
    {
        $artwork = $this->listedArtwork();

        $body = $this->getJson("/api/v1/search/artworks/{$artwork->id}")->json();

        $this->assertSame('https://schema.org', $body['schema_org']['@context']);
        $this->assertSame('VisualArtwork', $body['schema_org']['@type']);
    }

    public function test_schema_org_includes_title_and_medium(): void
    {
        $artwork = $this->listedArtwork();

        $schema = $this->getJson("/api/v1/search/artworks/{$artwork->id}")->json('schema_org');

        $this->assertSame('Schema Test Work', $schema['name']);
        $this->assertSame('painting', $schema['artMedium']);
    }

    public function test_schema_org_includes_date_created(): void
    {
        $artwork = $this->listedArtwork(['year_created' => 1985]);

        $schema = $this->getJson("/api/v1/search/artworks/{$artwork->id}")->json('schema_org');

        $this->assertSame('1985', $schema['dateCreated']);
    }

    public function test_schema_org_includes_iiif_manifest_link(): void
    {
        $artwork = $this->listedArtwork();

        $schema = $this->getJson("/api/v1/search/artworks/{$artwork->id}")->json('schema_org');

        $additionalProps = collect($schema['additionalProperty'] ?? []);
        $iiif = $additionalProps->firstWhere('name', 'iiifManifest');

        $this->assertNotNull($iiif);
        $this->assertStringContainsString('/iiif/manifest', $iiif['value']);
    }

    public function test_schema_org_original_edition(): void
    {
        $artwork = $this->listedArtwork(['is_original' => true]);

        $schema = $this->getJson("/api/v1/search/artworks/{$artwork->id}")->json('schema_org');

        $this->assertSame('original', $schema['artEdition']);
    }
}
