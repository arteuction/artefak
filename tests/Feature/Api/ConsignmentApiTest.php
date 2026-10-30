<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for consignment endpoints:
 *   GET  /api/v1/consignments
 *   POST /api/v1/consignments
 *   GET  /api/v1/consignments/{consignment}
 *   POST /api/v1/consignments/{consignment}/terminate
 */
final class ConsignmentApiTest extends TestCase
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

    private function artwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Consign Art ' . uniqid(),
            'slug'        => 'con-art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
    }

    private function consignment(User $owner, User $consignor, Artwork $artwork, string $status = 'draft'): Consignment
    {
        return Consignment::create([
            'owner_id'      => $owner->id,
            'consignor_id'  => $consignor->id,
            'artwork_id'    => $artwork->id,
            'commission_bps'=> 1500,
            'status'        => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_consignments(): void
    {
        $this->getJson('/api/v1/consignments')->assertStatus(401);
    }

    public function test_user_sees_consignments_where_owner_or_consignor(): void
    {
        $artist    = $this->artist();
        $gallery   = $this->buyer();
        $other     = $this->buyer();
        $artwork   = $this->artwork($artist);
        $artwork2  = $this->artwork($artist);

        $this->consignment($artist, $gallery, $artwork);   // artist is owner
        $this->consignment($other, $artist, $artwork2);    // artist is consignor

        $response = $this->actingAs($artist)
            ->getJson('/api/v1/consignments')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_user_does_not_see_unrelated_consignments(): void
    {
        $artist1 = $this->artist();
        $artist2 = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist1);

        $this->consignment($artist1, $buyer, $artwork);

        $response = $this->actingAs($artist2)
            ->getJson('/api/v1/consignments')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $artwork2= $this->artwork($artist);

        $this->consignment($artist, $buyer, $artwork, 'draft');
        $this->consignment($artist, $buyer, $artwork2, 'active');

        $response = $this->actingAs($artist)
            ->getJson('/api/v1/consignments?status=draft')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('draft', $response->json('data.0.status'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_owner_can_show_consignment(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $con     = $this->consignment($artist, $buyer, $artwork);

        $this->actingAs($artist)
            ->getJson("/api/v1/consignments/{$con->id}")
            ->assertOk();
    }

    public function test_consignor_can_show_consignment(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $con     = $this->consignment($artist, $buyer, $artwork);

        $this->actingAs($buyer)
            ->getJson("/api/v1/consignments/{$con->id}")
            ->assertOk();
    }

    public function test_unrelated_user_cannot_show_consignment(): void
    {
        $artist   = $this->artist();
        $buyer    = $this->buyer();
        $intruder = $this->buyer();
        $artwork  = $this->artwork($artist);
        $con      = $this->consignment($artist, $buyer, $artwork);

        $this->actingAs($intruder)
            ->getJson("/api/v1/consignments/{$con->id}")
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_consignment(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);

        $this->postJson('/api/v1/consignments', [
            'artwork_id'   => $artwork->id,
            'consignor_id' => $buyer->id,
        ])->assertStatus(401);
    }

    public function test_artist_can_create_consignment(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);

        $response = $this->actingAs($artist)
            ->postJson('/api/v1/consignments', [
                'artwork_id'     => $artwork->id,
                'consignor_id'   => $buyer->id,
                'commission_bps' => 1500,
            ])
            ->assertStatus(201);

        $this->assertSame('draft', $response->json('status'));
        $this->assertSame($artist->id, $response->json('owner_id'));
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->artist())
            ->postJson('/api/v1/consignments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['artwork_id', 'consignor_id']);
    }

    public function test_store_validates_commission_bps_max(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);

        $this->actingAs($artist)
            ->postJson('/api/v1/consignments', [
                'artwork_id'     => $artwork->id,
                'consignor_id'   => $buyer->id,
                'commission_bps' => 10001,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commission_bps']);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_cancel_consignment(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $con     = $this->consignment($artist, $buyer, $artwork);

        $this->postJson("/api/v1/consignments/{$con->id}/terminate")
            ->assertStatus(401);
    }

    public function test_owner_can_cancel_draft_consignment(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $con     = $this->consignment($artist, $buyer, $artwork, 'draft');

        $response = $this->actingAs($artist)
            ->postJson("/api/v1/consignments/{$con->id}/terminate")
            ->assertOk();

        $this->assertSame('terminated', $response->json('data.status'));
    }

    public function test_cancelling_completed_consignment_returns_422(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $con     = $this->consignment($artist, $buyer, $artwork, 'completed');

        $this->actingAs($artist)
            ->postJson("/api/v1/consignments/{$con->id}/terminate")
            ->assertStatus(422);
    }

    public function test_cancelling_already_terminated_is_idempotent(): void
    {
        $artist  = $this->artist();
        $buyer   = $this->buyer();
        $artwork = $this->artwork($artist);
        $con     = $this->consignment($artist, $buyer, $artwork, 'terminated');

        $this->actingAs($artist)
            ->postJson("/api/v1/consignments/{$con->id}/terminate")
            ->assertOk();
    }

    public function test_unrelated_buyer_cannot_cancel_consignment(): void
    {
        $artist   = $this->artist();
        $buyer    = $this->buyer();
        $intruder = $this->buyer();
        $artwork  = $this->artwork($artist);
        $con      = $this->consignment($artist, $buyer, $artwork, 'draft');

        $this->actingAs($intruder)
            ->postJson("/api/v1/consignments/{$con->id}/terminate")
            ->assertStatus(403);
    }
}
