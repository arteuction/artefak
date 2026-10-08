<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtistProfile;
use App\Models\Evidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 34: Artist profile creation, Gallery creation, Evidence verification.
 */
final class Phase34ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Artist profile ──────────────────────────────────────────────────────

    public function test_user_can_create_artist_profile(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artist-profile', [
                'display_name' => 'Maria Ivanova',
                'bio'          => 'Contemporary painter.',
                'terms_version' => '2024-01',
            ])
            ->assertCreated()
            ->assertJsonFragment(['display_name' => 'Maria Ivanova', 'status' => 'pending']);
    }

    public function test_create_artist_profile_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Existing',
            'slug'         => 'existing-' . $user->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/artist-profile', ['display_name' => 'New Name'])
            ->assertOk()
            ->assertJsonFragment(['display_name' => 'Existing']); // returns existing
    }

    public function test_user_can_view_own_artist_profile(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        ArtistProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'My Profile',
            'slug'         => 'my-profile-' . $user->id,
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/artist-profile')
            ->assertOk()
            ->assertJsonFragment(['display_name' => 'My Profile']);
    }

    public function test_artist_profile_404_when_none_exists(): void
    {
        $user = User::factory()->create(['role' => 'artist']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/artist-profile')
            ->assertNotFound();
    }

    // ── Gallery creation ────────────────────────────────────────────────────

    public function test_admin_can_create_gallery(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/galleries', [
                'name' => 'New Gallery',
                'type' => 'private',
            ])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'New Gallery', 'status' => 'active']);
    }

    public function test_non_admin_cannot_create_gallery(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist, 'sanctum')
            ->postJson('/api/v1/galleries', ['name' => 'Sneaky Gallery'])
            ->assertForbidden();
    }

    // ── Evidence verification ────────────────────────────────────────────────

    private function makeEvidence(): Evidence
    {
        $user = User::factory()->create(['role' => 'artist']);
        return Evidence::create([
            'subject_type'        => 'App\\Models\\Artwork',
            'subject_id'          => 1,
            'type'                => 'authenticity',
            'verification_status' => 'pending',
        ]);
    }

    public function test_admin_can_verify_evidence(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $evidence = $this->makeEvidence();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/evidence/{$evidence->id}/verify", [
                'status' => 'verified',
                'notes'  => 'Document is authentic.',
            ])
            ->assertOk()
            ->assertJsonFragment(['verification_status' => 'verified']);
    }

    public function test_admin_can_reject_evidence(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $evidence = $this->makeEvidence();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/evidence/{$evidence->id}/verify", [
                'status' => 'rejected',
                'notes'  => 'Document expired.',
            ])
            ->assertOk()
            ->assertJsonFragment(['verification_status' => 'rejected']);
    }

    public function test_cannot_re_verify_already_verified_evidence(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $evidence = $this->makeEvidence();
        $evidence->update(['verification_status' => 'verified']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/evidence/{$evidence->id}/verify", ['status' => 'rejected'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_verify_evidence(): void
    {
        $artist   = User::factory()->create(['role' => 'artist']);
        $evidence = $this->makeEvidence();

        $this->actingAs($artist, 'sanctum')
            ->postJson("/api/v1/evidence/{$evidence->id}/verify", ['status' => 'verified'])
            ->assertForbidden();
    }
}
