<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for /api/v1/me and /api/v1/artist-profile.
 */
final class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    // ── GET /me ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_me(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_authenticated_user_can_view_own_profile(): void
    {
        $user = $this->buyer();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->assertSame($user->id, $response->json('id'));
        $this->assertSame($user->email, $response->json('email'));
    }

    // ── POST /artist-profile ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_artist_profile(): void
    {
        $this->postJson('/api/v1/artist-profile', [])->assertStatus(401);
    }

    public function test_user_can_create_artist_profile(): void
    {
        $user = $this->artist();

        $this->actingAs($user)
            ->postJson('/api/v1/artist-profile', [
                'display_name' => 'Jane Doe',
                'bio'          => 'Contemporary artist from Sofia.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('display_name', 'Jane Doe')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('artist_profiles', [
            'user_id'      => $user->id,
            'display_name' => 'Jane Doe',
        ]);
    }

    public function test_creating_artist_profile_twice_returns_existing(): void
    {
        $user = $this->artist();

        $this->actingAs($user)
            ->postJson('/api/v1/artist-profile', ['display_name' => 'First'])
            ->assertStatus(201);

        $this->actingAs($user)
            ->postJson('/api/v1/artist-profile', ['display_name' => 'Second'])
            ->assertOk()
            ->assertJsonPath('display_name', 'First');

        $this->assertSame(1, ArtistProfile::where('user_id', $user->id)->count());
    }

    public function test_create_artist_profile_validates_required_display_name(): void
    {
        $user = $this->artist();

        $this->actingAs($user)
            ->postJson('/api/v1/artist-profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['display_name']);
    }

    // ── GET /artist-profile ───────────────────────────────────────────────────

    public function test_unauthenticated_cannot_view_artist_profile(): void
    {
        $this->getJson('/api/v1/artist-profile')->assertStatus(401);
    }

    public function test_user_with_no_profile_gets_404(): void
    {
        $user = $this->artist();

        $this->actingAs($user)
            ->getJson('/api/v1/artist-profile')
            ->assertStatus(404);
    }

    public function test_user_can_view_own_artist_profile(): void
    {
        $user = $this->artist();
        ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Test Artist',
            'slug'         => 'test-artist-' . $user->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/artist-profile')
            ->assertOk()
            ->assertJsonPath('user_id', $user->id);
    }

    // ── PATCH /artist-profile ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_artist_profile(): void
    {
        $this->patchJson('/api/v1/artist-profile', [])->assertStatus(401);
    }

    public function test_user_without_profile_gets_404_on_update(): void
    {
        $user = $this->artist();

        $this->actingAs($user)
            ->patchJson('/api/v1/artist-profile', ['bio' => 'new bio'])
            ->assertStatus(404);
    }

    public function test_user_can_update_own_artist_profile(): void
    {
        $user = $this->artist();
        ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Old Name',
            'slug'         => 'old-name-' . $user->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/v1/artist-profile', [
                'display_name' => 'New Name',
                'bio'          => 'Updated bio.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('artist_profiles', [
            'user_id'      => $user->id,
            'display_name' => 'New Name',
        ]);
    }
}
