<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for artwork rights endpoints:
 *   GET   /api/v1/artworks/{artwork}/rights
 *   PATCH /api/v1/artworks/{artwork}/rights
 */
final class RightsApiTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function artwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Rights Art ' . uniqid(),
            'slug'        => 'rights-art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_rights_show_is_public(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->getJson("/api/v1/artworks/{$artwork->slug}/rights")
            ->assertOk();
    }

    public function test_rights_show_returns_structured_data(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/rights")
            ->assertOk();

        $this->assertIsArray($response->json());
    }

    public function test_rights_show_returns_404_for_unknown(): void
    {
        $this->getJson('/api/v1/artworks/nonexistent-slug-xyz/rights')
            ->assertStatus(404);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_update_rights(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->patchJson("/api/v1/artworks/{$artwork->slug}/rights", [
            'license_spdx' => 'CC-BY-4.0',
        ])->assertStatus(401);
    }

    public function test_artist_can_update_own_artwork_rights(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $response = $this->actingAs($artist)
            ->patchJson("/api/v1/artworks/{$artwork->slug}/rights", [
                'license_spdx'       => 'CC-BY-4.0',
                'resale_royalty_bps' => 500,
                'rights_statement'   => 'All rights reserved.',
            ])
            ->assertOk();

        $this->assertSame('CC-BY-4.0', $response->json('license_spdx'));
        $this->assertSame(500, $response->json('resale_royalty_bps'));
    }

    public function test_admin_can_update_any_artwork_rights(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/artworks/{$artwork->slug}/rights", [
                'license_spdx' => 'MIT',
            ])
            ->assertOk();
    }

    public function test_buyer_cannot_update_rights_of_other_artists_artwork(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($this->buyer())
            ->patchJson("/api/v1/artworks/{$artwork->slug}/rights", [
                'license_spdx' => 'CC0-1.0',
            ])
            ->assertStatus(403);
    }

    public function test_rights_update_validates_license_spdx_format(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($artist)
            ->patchJson("/api/v1/artworks/{$artwork->slug}/rights", [
                'license_spdx' => 'Invalid License Name!!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['license_spdx']);
    }

    public function test_rights_update_validates_resale_royalty_bps_max(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($artist)
            ->patchJson("/api/v1/artworks/{$artwork->slug}/rights", [
                'resale_royalty_bps' => 5001,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resale_royalty_bps']);
    }
}
