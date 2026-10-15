<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ArtistProfile;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public catalog endpoint tests.
 *
 * Routes: GET /api/v1/search/artworks
 *         GET /api/v1/search/artworks/{artwork}
 */
final class PublicArtworkCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function listedArtwork(User $owner, array $overrides = []): Artwork
    {
        return Artwork::create(array_merge([
            'user_id'   => $owner->id,
            'title'     => 'Public Piece ' . Str::random(4),
            'slug'      => Str::uuid()->toString(),
            'status'    => 'listed',
            'is_original' => true,
        ], $overrides));
    }

    // ── Index: response shape ─────────────────────────────────────────────────

    public function test_index_returns_listed_artworks_as_dto(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist, ['title' => 'Visible']);
        $this->listedArtwork($artist, ['title' => 'Draft', 'status' => 'draft']);

        $json = $this->getJson('/api/v1/search/artworks')
            ->assertOk()
            ->json();

        $titles = array_column($json['data'], 'title');
        $this->assertContains('Visible', $titles);
        $this->assertNotContains('Draft', $titles);
    }

    public function test_index_response_contains_expected_dto_keys(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist);

        $response = $this->getJson('/api/v1/search/artworks')->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('id',           $item);
        $this->assertArrayHasKey('title',        $item);
        $this->assertArrayHasKey('slug',         $item);
        $this->assertArrayHasKey('artist',       $item);
        $this->assertArrayHasKey('medium',       $item);
        $this->assertArrayHasKey('is_original',  $item);
    }

    public function test_index_includes_artist_profile_slug(): void
    {
        $artist = $this->artist();

        // Create an artist profile for the slug
        ArtistProfile::create([
            'user_id'      => $artist->id,
            'slug'         => 'test-artist-slug',
            'display_name' => 'Test Artist',
            'bio'          => null,
            'website'      => null,
        ]);

        $this->listedArtwork($artist, ['title' => 'With Profile']);

        $response = $this->getJson('/api/v1/search/artworks')->assertOk();

        $item = $response->json('data.0');
        $this->assertNotNull($item['artist']['profile_slug'] ?? null,
            'artist.profile_slug must be populated when an ArtistProfile exists.');
        $this->assertSame('test-artist-slug', $item['artist']['profile_slug']);
    }

    public function test_index_meta_contains_pagination_fields(): void
    {
        $artist = $this->artist();
        $this->listedArtwork($artist);

        $meta = $this->getJson('/api/v1/search/artworks')
            ->assertOk()
            ->json('meta');

        $this->assertArrayHasKey('total',        $meta);
        $this->assertArrayHasKey('per_page',     $meta);
        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('last_page',    $meta);
    }

    // ── Show: ETag + Cache-Control ────────────────────────────────────────────

    public function test_show_returns_etag_header(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $this->getJson("/api/v1/search/artworks/{$artwork->slug}")
            ->assertOk()
            ->assertHeader('ETag');
    }

    public function test_show_returns_cache_control_header(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $response = $this->getJson("/api/v1/search/artworks/{$artwork->slug}")
            ->assertOk();

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public',    $cacheControl,
            'Listed artwork detail must be publicly cacheable.');
        $this->assertStringContainsString('max-age=',  $cacheControl);
    }

    public function test_show_returns_304_on_matching_etag(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        // First request to get the ETag
        $first = $this->getJson("/api/v1/search/artworks/{$artwork->slug}");
        $etag  = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        // Second request with If-None-Match — should be 304
        $this->call('GET', "/api/v1/search/artworks/{$artwork->slug}", [], [], [], [
            'HTTP_IF_NONE_MATCH'  => $etag,
            'HTTP_ACCEPT'         => 'application/json',
        ])->assertStatus(304);
    }

    public function test_show_returns_200_when_etag_does_not_match(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $this->call('GET', "/api/v1/search/artworks/{$artwork->slug}", [], [], [], [
            'HTTP_IF_NONE_MATCH'  => '"stale-etag-value"',
            'HTTP_ACCEPT'         => 'application/json',
        ])->assertOk();
    }

    public function test_show_includes_artist_profile_slug(): void
    {
        $artist = $this->artist();
        ArtistProfile::create([
            'user_id'      => $artist->id,
            'slug'         => 'detail-artist-slug',
            'display_name' => 'Detail Artist',
            'bio'          => null,
            'website'      => null,
        ]);

        $artwork = $this->listedArtwork($artist, ['title' => 'Detail Piece']);

        $response = $this->getJson("/api/v1/search/artworks/{$artwork->slug}")
            ->assertOk();

        $this->assertSame('detail-artist-slug',
            $response->json('data.artist.profile_slug'),
            'artist.profile_slug must be populated on the detail endpoint.');
    }

    public function test_show_404_for_draft_artwork(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist, ['status' => 'draft']);

        $this->getJson("/api/v1/search/artworks/{$artwork->slug}")
            ->assertNotFound();
    }

    public function test_show_response_uses_dto_keys(): void
    {
        $artist  = $this->artist();
        $artwork = $this->listedArtwork($artist);

        $data = $this->getJson("/api/v1/search/artworks/{$artwork->slug}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('id',          $data);
        $this->assertArrayHasKey('title',       $data);
        $this->assertArrayHasKey('slug',        $data);
        $this->assertArrayHasKey('artist',      $data);
        $this->assertArrayHasKey('is_original', $data);
    }
}
