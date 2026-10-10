<?php

declare(strict_types=1);

namespace Tests\Feature\Iiif;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 97 — IIIF Image API 3.0 + Presentation API 3.0.
 */
final class IiifTest extends TestCase
{
    use RefreshDatabase;

    private function artworkWithImage(array $override = []): Artwork
    {
        $user = User::factory()->create(['role' => 'artist']);
        return Artwork::create(array_merge([
            'user_id'              => $user->id,
            'title'                => 'IIIF Test Work',
            'slug'                 => Str::uuid()->toString(),
            'status'               => 'listed',
            'medium'               => 'painting',
            'is_original'          => true,
            'primary_image_key'    => 'artworks/test/primary.webp',
            'primary_image_status' => 'confirmed',
        ], $override));
    }

    // ── info.json ─────────────────────────────────────────────────────────────

    public function test_info_json_returns_iiif_image_api_3_descriptor(): void
    {
        $artwork = $this->artworkWithImage();

        $res = $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/info.json")
            ->assertOk();

        $body = $res->json();
        $this->assertSame('ImageService3', $body['type']);
        $this->assertStringContainsString('iiif.io/api/image/3', $body['@context']);
        $this->assertSame('level1', $body['profile']);
    }

    public function test_info_json_returns_404_for_draft_artwork(): void
    {
        $artwork = $this->artworkWithImage(['status' => 'draft']);
        $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/info.json")->assertNotFound();
    }

    public function test_info_json_returns_404_when_no_confirmed_image(): void
    {
        $artwork = $this->artworkWithImage([
            'primary_image_key'    => null,
            'primary_image_status' => null,
        ]);
        $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/info.json")->assertNotFound();
    }

    public function test_info_json_id_is_canonical_iiif_url(): void
    {
        $artwork = $this->artworkWithImage();
        $body    = $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/info.json")->json();

        $this->assertStringEndsWith("/api/v1/artworks/{$artwork->id}/iiif", $body['id']);
    }

    // ── manifest ──────────────────────────────────────────────────────────────

    public function test_manifest_returns_iiif_presentation_3_manifest(): void
    {
        $artwork = $this->artworkWithImage();

        $body = $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/manifest")
            ->assertOk()
            ->json();

        $this->assertSame('Manifest', $body['type']);
        $this->assertStringContainsString('iiif.io/api/presentation/3', $body['@context']);
        $this->assertSame(['en' => ['IIIF Test Work']], $body['label']);
    }

    public function test_manifest_contains_canvas_with_annotation(): void
    {
        $artwork = $this->artworkWithImage();

        $body = $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/manifest")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($body['items']);
        $this->assertSame('Canvas', $body['items'][0]['type']);
    }

    public function test_manifest_returns_404_for_draft(): void
    {
        $artwork = $this->artworkWithImage(['status' => 'draft']);
        $this->getJson("/api/v1/artworks/{$artwork->id}/iiif/manifest")->assertNotFound();
    }
}
