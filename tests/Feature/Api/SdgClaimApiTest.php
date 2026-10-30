<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SDG claim endpoints:
 *   GET  /api/v1/artworks/{artwork}/sdg-claims
 *   POST /api/v1/artworks/{artwork}/sdg-claims
 *   GET  /api/v1/artworks/{artwork}/sdg-claims/{claim}
 *   POST /api/v1/artworks/{artwork}/sdg-claims/{claim}/review
 */
final class SdgClaimApiTest extends TestCase
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
            'title'       => 'SDG Art ' . uniqid(),
            'slug'        => 'sdg-art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
    }

    private function claim(Artwork $artwork, int $sdg = 7, string $status = 'pending'): ArtworkSdgClaim
    {
        return ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => $sdg,
            'rationale'  => 'This artwork promotes SDG ' . $sdg,
            'status'     => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_public_sees_only_approved_claims(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $this->claim($artwork, 7, 'pending');
        $this->claim($artwork, 13, 'approved');

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/sdg-claims")
            ->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('approved', $response->json('0.status'));
    }

    public function test_artwork_owner_sees_all_claims(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $this->claim($artwork, 7, 'pending');
        $this->claim($artwork, 13, 'approved');

        $response = $this->actingAs($artist)
            ->getJson("/api/v1/artworks/{$artwork->slug}/sdg-claims")
            ->assertOk();

        $this->assertCount(2, $response->json());
    }

    public function test_admin_sees_all_claims(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $this->claim($artwork, 7, 'pending');
        $this->claim($artwork, 13, 'rejected');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/artworks/{$artwork->slug}/sdg-claims")
            ->assertOk();

        $this->assertCount(2, $response->json());
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_submit_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims", [
            'sdg_number' => 7,
            'rationale'  => 'Clean energy art.',
        ])->assertStatus(401);
    }

    public function test_artist_can_submit_sdg_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $response = $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims", [
                'sdg_number' => 7,
                'rationale'  => 'Clean energy art.',
            ])
            ->assertStatus(201);

        $this->assertSame(7, $response->json('sdg_number'));
        $this->assertSame('pending', $response->json('status'));
    }

    public function test_submitting_again_upserts_existing_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims", [
                'sdg_number' => 7,
                'rationale'  => 'First rationale.',
            ])
            ->assertStatus(201);

        $response = $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims", [
                'sdg_number' => 7,
                'rationale'  => 'Updated rationale.',
            ])
            ->assertOk();

        $this->assertSame('Updated rationale.', $response->json('rationale'));
        $this->assertDatabaseCount('artwork_sdg_claims', 1);
    }

    public function test_store_validates_sdg_number_range(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims", [
                'sdg_number' => 18,
                'rationale'  => 'Out of range.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sdg_number']);
    }

    public function test_buyer_cannot_submit_claim_for_others_artwork(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($this->buyer())
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims", [
                'sdg_number' => 7,
                'rationale'  => 'Unauthorized.',
            ])
            ->assertStatus(403);
    }

    // ── review ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_review_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $claim   = $this->claim($artwork, 7, 'pending');

        $this->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims/{$claim->id}/review", [
            'decision' => 'approved',
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_review_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $claim   = $this->claim($artwork, 7, 'pending');

        $this->actingAs($this->buyer())
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims/{$claim->id}/review", [
                'decision' => 'approved',
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_approve_pending_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $claim   = $this->claim($artwork, 7, 'pending');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims/{$claim->id}/review", [
                'decision'    => 'approved',
                'review_note' => 'Well documented.',
            ])
            ->assertOk();

        $this->assertSame('approved', $response->json('status'));
        $this->assertSame('Well documented.', $response->json('review_note'));
    }

    public function test_admin_can_reject_pending_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $claim   = $this->claim($artwork, 13, 'pending');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims/{$claim->id}/review", [
                'decision' => 'rejected',
            ])
            ->assertOk();

        $this->assertSame('rejected', $response->json('status'));
    }

    public function test_review_rejects_already_approved_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $claim   = $this->claim($artwork, 7, 'approved');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims/{$claim->id}/review", [
                'decision' => 'rejected',
            ])
            ->assertStatus(422);
    }

    public function test_review_validates_decision_enum(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $claim   = $this->claim($artwork, 7, 'pending');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/artworks/{$artwork->slug}/sdg-claims/{$claim->id}/review", [
                'decision' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }
}
