<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistProfile;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 56: Public artist portfolio endpoints.
 */
final class Phase56ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprovedArtist(): array
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'artist']);
        $user = $user->fresh();

        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Test Artist',
            'slug'         => 'test-artist-' . uniqid(),
            'bio'          => 'A fine artist.',
            'status'       => 'approved',
        ]);

        return [$user, $profile];
    }

    private function makeListedArtwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Painting ' . uniqid(),
            'slug'         => 'painting-' . uniqid(),
            'status'       => 'listed',
            'currency'     => 'BGN',
            'year_created' => 2023,
            'medium'       => 'painting',
        ]);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_approved_artists_only(): void
    {
        [$user1, $approved] = $this->makeApprovedArtist();

        $user2 = User::factory()->create();
        ArtistProfile::create([
            'user_id'      => $user2->id,
            'display_name' => 'Pending Artist',
            'slug'         => 'pending-artist-' . uniqid(),
            'status'       => 'pending',
        ]);

        $response = $this->getJson('/api/v1/artists');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains($approved->slug, $slugs);
    }

    public function test_index_returns_pagination_meta(): void
    {
        $this->makeApprovedArtist();

        $this->getJson('/api/v1/artists')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page']]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_profile_and_artworks(): void
    {
        [$user, $profile] = $this->makeApprovedArtist();
        $artwork = $this->makeListedArtwork($user);

        $response = $this->getJson("/api/v1/artists/{$profile->slug}");

        $response->assertOk()
            ->assertJsonPath('profile.slug', $profile->slug)
            ->assertJsonPath('profile.display_name', 'Test Artist');

        $artworkIds = collect($response->json('artworks.data'))->pluck('id')->all();
        $this->assertContains($artwork->id, $artworkIds);
    }

    public function test_show_excludes_draft_artworks(): void
    {
        [$user, $profile] = $this->makeApprovedArtist();
        $draft = Artwork::create([
            'user_id'      => $user->id,
            'title'        => 'Draft Painting',
            'slug'         => 'draft-painting-' . uniqid(),
            'status'       => 'draft',
            'currency'     => 'BGN',
            'year_created' => 2023,
            'medium'       => 'painting',
        ]);

        $response = $this->getJson("/api/v1/artists/{$profile->slug}");

        $response->assertOk();
        $artworkIds = collect($response->json('artworks.data'))->pluck('id')->all();
        $this->assertNotContains($draft->id, $artworkIds);
    }

    public function test_show_returns_404_for_pending_profile(): void
    {
        $user = User::factory()->create();
        $profile = ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Hidden Artist',
            'slug'         => 'hidden-artist-' . uniqid(),
            'status'       => 'pending',
        ]);

        $this->getJson("/api/v1/artists/{$profile->slug}")
            ->assertNotFound();
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/v1/artists/does-not-exist-xyz')
            ->assertNotFound();
    }

    public function test_show_includes_recent_sales_array(): void
    {
        [$user, $profile] = $this->makeApprovedArtist();

        $response = $this->getJson("/api/v1/artists/{$profile->slug}");

        $response->assertOk()
            ->assertJsonStructure(['profile', 'artworks', 'recent_sales']);
    }
}
