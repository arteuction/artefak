<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistProfile;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the public artist portfolio endpoints:
 *   GET /api/v1/artists
 *   GET /api/v1/artists/{slug}
 */
final class ArtistPortfolioApiTest extends TestCase
{
    use RefreshDatabase;

    private function approvedArtist(string $displayName = 'Test Artist'): array
    {
        $user = User::factory()->create(['role' => 'artist']);

        $slug    = 'artist-' . $user->id;
        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => $displayName,
            'slug'         => $slug,
            'bio'          => 'A great artist.',
            'status'       => 'approved',
        ]);

        return [$user, $profile];
    }

    private function pendingArtist(): array
    {
        $user = User::factory()->create(['role' => 'artist']);

        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Pending Artist',
            'slug'         => 'pending-artist-' . $user->id,
            'status'       => 'pending',
        ]);

        return [$user, $profile];
    }

    // ── GET /api/v1/artists ──────────────────────────────────────────────────

    public function test_index_is_public(): void
    {
        $this->getJson('/api/v1/artists')->assertOk();
    }

    public function test_index_returns_only_approved_profiles(): void
    {
        $this->approvedArtist('Approved One');
        $this->pendingArtist();

        $response = $this->getJson('/api/v1/artists')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Approved One', $response->json('data.0.display_name'));
    }

    public function test_index_response_structure(): void
    {
        $this->approvedArtist();

        $this->getJson('/api/v1/artists')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'display_name', 'slug', 'bio']],
                'meta' => ['total', 'current_page', 'last_page'],
            ]);
    }

    public function test_index_returns_empty_when_no_approved_artists(): void
    {
        $this->pendingArtist();

        $response = $this->getJson('/api/v1/artists')->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_index_does_not_expose_user_id(): void
    {
        $this->approvedArtist();

        $response = $this->getJson('/api/v1/artists')->assertOk();

        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    // ── GET /api/v1/artists/{slug} ───────────────────────────────────────────

    public function test_show_is_public(): void
    {
        [, $profile] = $this->approvedArtist();

        $this->getJson("/api/v1/artists/{$profile->slug}")->assertOk();
    }

    public function test_show_returns_profile_data(): void
    {
        [, $profile] = $this->approvedArtist('The Artist');

        $response = $this->getJson("/api/v1/artists/{$profile->slug}")->assertOk();

        $this->assertSame('The Artist', $response->json('profile.display_name'));
        $this->assertSame($profile->slug, $response->json('profile.slug'));
    }

    public function test_show_response_structure(): void
    {
        [, $profile] = $this->approvedArtist();

        $this->getJson("/api/v1/artists/{$profile->slug}")
            ->assertOk()
            ->assertJsonStructure([
                'profile'      => ['id', 'display_name', 'slug', 'bio'],
                'artworks'     => ['data', 'meta' => ['total', 'current_page', 'last_page']],
                'recent_sales' => [],
            ]);
    }

    public function test_show_returns_404_for_non_existent_slug(): void
    {
        $this->getJson('/api/v1/artists/slug-that-does-not-exist')->assertStatus(404);
    }

    public function test_show_returns_404_for_pending_artist(): void
    {
        [, $profile] = $this->pendingArtist();

        $this->getJson("/api/v1/artists/{$profile->slug}")->assertStatus(404);
    }

    public function test_show_includes_listed_artworks(): void
    {
        [$user, $profile] = $this->approvedArtist();

        Artwork::create([
            'user_id'     => $user->id,
            'title'       => 'My Painting',
            'slug'        => 'my-painting-' . $user->id,
            'status'      => 'listed',
            'is_original' => true,
        ]);

        $response = $this->getJson("/api/v1/artists/{$profile->slug}")->assertOk();

        $this->assertSame(1, $response->json('artworks.meta.total'));
        $this->assertSame('My Painting', $response->json('artworks.data.0.title'));
    }

    public function test_show_excludes_non_listed_artworks(): void
    {
        [$user, $profile] = $this->approvedArtist();

        Artwork::create([
            'user_id'     => $user->id,
            'title'       => 'Draft Painting',
            'slug'        => 'draft-painting-' . $user->id,
            'status'      => 'draft',
            'is_original' => true,
        ]);

        $response = $this->getJson("/api/v1/artists/{$profile->slug}")->assertOk();

        $this->assertSame(0, $response->json('artworks.meta.total'));
    }

    public function test_show_returns_empty_recent_sales_when_none(): void
    {
        [, $profile] = $this->approvedArtist();

        $response = $this->getJson("/api/v1/artists/{$profile->slug}")->assertOk();

        $this->assertEmpty($response->json('recent_sales'));
    }

    public function test_index_is_ordered_by_display_name(): void
    {
        $this->approvedArtist('Zara');
        $this->approvedArtist('Aaron');

        $response = $this->getJson('/api/v1/artists')->assertOk();

        $this->assertSame('Aaron', $response->json('data.0.display_name'));
        $this->assertSame('Zara', $response->json('data.1.display_name'));
    }
}
