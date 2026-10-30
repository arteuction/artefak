<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for public artwork catalog endpoints:
 *   GET /api/v1/artworks
 *   GET /api/v1/artworks/{artwork}
 *   GET /api/v1/artworks/{artwork}/iiif/info.json
 *   GET /api/v1/artworks/{artwork}/iiif/manifest
 */
final class PublicArtworkApiTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function listedArtwork(User $artist, array $attrs = []): Artwork
    {
        return Artwork::create(array_merge([
            'user_id'     => $artist->id,
            'title'       => 'Listed Art ' . uniqid(),
            'slug'        => 'listed-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ], $attrs));
    }

    private function draftArtwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Draft Art ' . uniqid(),
            'slug'        => 'draft-' . uniqid(),
            'status'      => 'draft',
            'is_original' => true,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_artworks_index_is_public(): void
    {
        $this->getJson('/api/v1/artworks')->assertOk();
    }

    public function test_index_returns_only_listed_artworks(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist);
        $this->draftArtwork($artist);

        $response = $this->getJson('/api/v1/artworks')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_index_can_filter_by_medium(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, ['medium' => 'painting']);
        $this->listedArtwork($artist, ['medium' => 'sculpture']);

        $response = $this->getJson('/api/v1/artworks?medium=painting')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_index_can_search_by_title(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist, ['title' => 'Golden Sunrise']);
        $this->listedArtwork($artist, ['title' => 'Winter Landscape']);

        $response = $this->getJson('/api/v1/artworks?q=Golden')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertStringContainsString('Golden', $response->json('data.0.title'));
    }

    public function test_index_validates_medium_enum(): void
    {
        $this->getJson('/api/v1/artworks?medium=watercolor')
            ->assertStatus(422);
    }

    public function test_index_validates_sdg_range(): void
    {
        $this->getJson('/api/v1/artworks?sdg=18')
            ->assertStatus(422);
    }

    public function test_index_response_has_data_and_meta_keys(): void
    {
        $response = $this->getJson('/api/v1/artworks')->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('meta', $response->json());
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_is_public(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $this->getJson("/api/v1/artworks/{$artwork->slug}")->assertOk();
    }

    public function test_show_returns_data_and_schema_org_keys(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}")->assertOk();

        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('schema_org', $response->json());
    }

    public function test_show_returns_404_for_draft_artwork(): void
    {
        $artist  = $this->artist();
        $artwork = $this->draftArtwork($artist);

        $this->getJson("/api/v1/artworks/{$artwork->slug}")->assertStatus(404);
    }

    public function test_show_returns_304_for_matching_etag(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $firstResponse = $this->getJson("/api/v1/artworks/{$artwork->slug}");
        $etag = $firstResponse->headers->get('ETag');

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson("/api/v1/artworks/{$artwork->slug}")
            ->assertStatus(304);
    }

    // ── IIIF ─────────────────────────────────────────────────────────────────

    public function test_iiif_info_returns_404_for_non_listed_artwork(): void
    {
        $artist  = $this->artist();
        $artwork = $this->draftArtwork($artist);

        $this->getJson("/api/v1/artworks/{$artwork->slug}/iiif/info.json")
            ->assertStatus(404);
    }

    public function test_iiif_info_returns_404_when_no_primary_image(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $this->getJson("/api/v1/artworks/{$artwork->slug}/iiif/info.json")
            ->assertStatus(404);
    }

    public function test_iiif_info_returns_json_ld_for_confirmed_image(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist, [
            'primary_image_key'    => 'artworks/test.webp',
            'primary_image_status' => 'confirmed',
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/iiif/info.json")
            ->assertOk();

        $this->assertSame('ImageService3', $response->json('type'));
        $this->assertSame('http://iiif.io/api/image/3/context.json', $response->json('@context'));
    }

    public function test_iiif_manifest_returns_404_for_non_listed(): void
    {
        $artist  = $this->artist();
        $artwork = $this->draftArtwork($artist);

        $this->getJson("/api/v1/artworks/{$artwork->slug}/iiif/manifest")
            ->assertStatus(404);
    }

    public function test_iiif_manifest_returns_presentation_manifest(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/iiif/manifest")
            ->assertOk();

        $this->assertSame('Manifest', $response->json('type'));
        $this->assertArrayHasKey('@context', $response->json());
    }
}
