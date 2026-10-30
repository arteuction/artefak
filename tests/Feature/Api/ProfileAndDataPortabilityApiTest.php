<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for profile and data portability endpoints:
 *   GET   /api/v1/me
 *   POST  /api/v1/artist-profile
 *   GET   /api/v1/artist-profile
 *   PATCH /api/v1/artist-profile
 *   GET   /api/v1/my/export
 */
final class ProfileAndDataPortabilityApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    // ── GET /me ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_get_me(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_buyer_can_get_own_profile(): void
    {
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->assertSame($buyer->id, $response->json('id'));
        $this->assertArrayNotHasKey('password', $response->json());
    }

    // ── POST /artist-profile ──────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_artist_profile(): void
    {
        $this->postJson('/api/v1/artist-profile', ['display_name' => 'Test'])
            ->assertStatus(401);
    }

    public function test_user_can_create_artist_profile(): void
    {
        $artist = $this->artist();

        $response = $this->actingAs($artist)
            ->postJson('/api/v1/artist-profile', [
                'display_name' => 'Ivan Ivanov',
                'bio'          => 'Painter from Sofia.',
            ])
            ->assertStatus(201);

        $this->assertSame('Ivan Ivanov', $response->json('display_name'));
        $this->assertSame('pending', $response->json('status'));
    }

    public function test_creating_artist_profile_twice_is_idempotent(): void
    {
        $artist = $this->artist();

        $this->actingAs($artist)
            ->postJson('/api/v1/artist-profile', ['display_name' => 'Ivan'])
            ->assertStatus(201);

        $this->actingAs($artist)
            ->postJson('/api/v1/artist-profile', ['display_name' => 'Ivan'])
            ->assertOk();

        $this->assertDatabaseCount('artist_profiles', 1);
    }

    public function test_create_artist_profile_validates_required_display_name(): void
    {
        $this->actingAs($this->artist())
            ->postJson('/api/v1/artist-profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['display_name']);
    }

    public function test_create_artist_profile_validates_website_url(): void
    {
        $this->actingAs($this->artist())
            ->postJson('/api/v1/artist-profile', [
                'display_name' => 'Ivan',
                'website'      => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['website']);
    }

    // ── GET /artist-profile ───────────────────────────────────────────────────

    public function test_unauthenticated_cannot_get_artist_profile(): void
    {
        $this->getJson('/api/v1/artist-profile')->assertStatus(401);
    }

    public function test_user_with_profile_can_get_own_profile(): void
    {
        $artist = $this->artist();
        ArtistProfile::create([
            'user_id'      => $artist->id,
            'display_name' => 'Ivan Ivanov',
            'slug'         => 'ivan-' . $artist->id,
            'status'       => 'pending',
        ]);

        $response = $this->actingAs($artist)
            ->getJson('/api/v1/artist-profile')
            ->assertOk();

        $this->assertSame('Ivan Ivanov', $response->json('display_name'));
    }

    public function test_user_without_profile_gets_404(): void
    {
        $this->actingAs($this->artist())
            ->getJson('/api/v1/artist-profile')
            ->assertStatus(404);
    }

    // ── PATCH /artist-profile ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_artist_profile(): void
    {
        $this->patchJson('/api/v1/artist-profile', ['bio' => 'New bio.'])
            ->assertStatus(401);
    }

    public function test_artist_can_update_own_profile(): void
    {
        $artist = $this->artist();
        ArtistProfile::create([
            'user_id'      => $artist->id,
            'display_name' => 'Ivan',
            'slug'         => 'ivan-' . $artist->id,
            'status'       => 'pending',
        ]);

        $response = $this->actingAs($artist)
            ->patchJson('/api/v1/artist-profile', ['bio' => 'Updated bio.'])
            ->assertOk();

        $this->assertSame('Updated bio.', $response->json('bio'));
    }

    public function test_update_artist_profile_returns_404_if_no_profile(): void
    {
        $this->actingAs($this->artist())
            ->patchJson('/api/v1/artist-profile', ['bio' => 'Bio.'])
            ->assertStatus(404);
    }

    // ── GET /my/export ────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_export_data(): void
    {
        $this->getJson('/api/v1/my/export')->assertStatus(401);
    }

    public function test_buyer_can_export_own_data(): void
    {
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)
            ->getJson('/api/v1/my/export')
            ->assertOk();

        $this->assertArrayHasKey('exported_at', $response->json());
    }
}
