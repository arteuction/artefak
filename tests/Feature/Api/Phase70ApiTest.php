<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 70: Consignment lifecycle API — authorization + full workflow.
 *
 * Sections:
 *   A) Create consignment — artwork owner creates, non-owner blocked
 *   B) Approve — gallery owner/curator can approve; non-staff blocked
 *   C) Activate — owner or consignor can activate a draft without gallery
 *   D) Create lot — gallery curator creates lot from active consignment
 *   E) Terminate — owner/consignor cancel draft; admin override; blocks active lots
 *   F) Visibility — index/show scoped to caller; outsider cannot read
 */
final class Phase70ApiTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // A. Create consignment
    // ────────────────────────────────────────────────────────────────────────

    public function test_artwork_owner_can_create_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/consignments', [
                'artwork_id'     => $artwork->id,
                'consignor_id'   => $owner->id,
                'commission_bps' => 1500,
            ])
            ->assertCreated();

        $response->assertJsonStructure(['id', 'status', 'artwork_id', 'commission_bps']);
        $this->assertSame('draft', $response->json('status'));
        $this->assertSame(1500, $response->json('commission_bps'));
    }

    public function test_create_consignment_with_gallery(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery = $this->makeGallery();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/consignments', [
                'artwork_id'     => $artwork->id,
                'consignor_id'   => $owner->id,
                'gallery_id'     => $gallery->id,
                'commission_bps' => 2000,
                'notes'          => 'Autumn exhibition',
            ])
            ->assertCreated();

        $this->assertSame($gallery->id, $response->json('gallery_id'));
        $this->assertSame('Autumn exhibition', $response->json('notes'));
    }

    public function test_unauthenticated_cannot_create_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();

        $this->postJson('/api/v1/consignments', [
            'artwork_id'   => $artwork->id,
            'consignor_id' => $owner->id,
        ])->assertUnauthorized();
    }

    public function test_create_consignment_validates_artwork_exists(): void
    {
        $owner = User::factory()->create(['role' => 'artist']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/consignments', [
                'artwork_id'   => 99999,
                'consignor_id' => $owner->id,
            ])
            ->assertUnprocessable();
    }

    // ────────────────────────────────────────────────────────────────────────
    // B. Approve
    // ────────────────────────────────────────────────────────────────────────

    public function test_gallery_curator_can_approve_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery   = $this->makeGallery();
        $curator   = $this->addStaff($gallery, 'curator');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery);

        $this->actingAs($curator, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/approve")
            ->assertOk();

        $consignment->refresh();
        $this->assertSame('active', $consignment->status);
    }

    public function test_gallery_owner_can_approve_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery     = $this->makeGallery();
        $galleryOwner = $this->addStaff($gallery, 'owner');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery);

        $this->actingAs($galleryOwner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/approve")
            ->assertOk();

        $this->assertSame('active', $consignment->refresh()->status);
    }

    public function test_gallery_sales_staff_cannot_approve_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery   = $this->makeGallery();
        $sales     = $this->addStaff($gallery, 'sales');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery);

        $this->actingAs($sales, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/approve")
            ->assertUnprocessable();
    }

    public function test_non_gallery_staff_cannot_approve_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery  = $this->makeGallery();
        $outsider = User::factory()->create(['role' => 'buyer']);

        $consignment = $this->makeConsignment($artwork, $owner, $gallery);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/approve")
            ->assertUnprocessable();
    }

    public function test_cannot_approve_already_active_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery  = $this->makeGallery();
        $curator  = $this->addStaff($gallery, 'curator');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery, status: 'active');

        $this->actingAs($curator, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/approve")
            ->assertUnprocessable();
    }

    // ────────────────────────────────────────────────────────────────────────
    // C. Activate (no gallery — owner/consignor self-activates)
    // ────────────────────────────────────────────────────────────────────────

    public function test_owner_can_activate_draft_consignment_without_gallery(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/activate")
            ->assertOk()
            ->assertJsonPath('status', 'active');
    }

    public function test_outsider_cannot_activate_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $outsider = User::factory()->create(['role' => 'buyer']);
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/activate")
            ->assertForbidden();
    }

    // ────────────────────────────────────────────────────────────────────────
    // D. Create lot from consignment
    // ────────────────────────────────────────────────────────────────────────

    public function test_curator_can_create_auction_lot_from_active_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery  = $this->makeGallery();
        $curator  = $this->addStaff($gallery, 'curator');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery, status: 'active');

        $response = $this->actingAs($curator, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/create-lot", [
                'sale_mode'          => 'auction',
                'starting_bid_cents' => 10000,
                'currency'           => 'EUR',
            ])
            ->assertCreated();

        $response->assertJsonStructure(['id', 'sale_mode', 'status', 'artwork']);
        $this->assertSame('auction', $response->json('sale_mode'));
        $this->assertSame('active', $response->json('status'));
        $this->assertSame(10000, $response->json('starting_bid_cents'));
    }

    public function test_cannot_create_lot_from_draft_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery  = $this->makeGallery();
        $curator  = $this->addStaff($gallery, 'curator');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery, status: 'draft');

        $this->actingAs($curator, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/create-lot", [
                'sale_mode' => 'auction',
            ])
            ->assertUnprocessable();
    }

    public function test_hybrid_lot_requires_buy_now_price(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery  = $this->makeGallery();
        $curator  = $this->addStaff($gallery, 'curator');

        $consignment = $this->makeConsignment($artwork, $owner, $gallery, status: 'active');

        $this->actingAs($curator, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/create-lot", [
                'sale_mode' => 'hybrid',
                // missing buy_now_price_cents
            ])
            ->assertUnprocessable();
    }

    // ────────────────────────────────────────────────────────────────────────
    // E. Terminate
    // ────────────────────────────────────────────────────────────────────────

    public function test_owner_can_terminate_draft_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');

        $this->assertSame('terminated', $consignment->refresh()->status);
    }

    public function test_terminate_is_idempotent_when_already_terminated(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'terminated',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertOk();
    }

    public function test_outsider_cannot_terminate_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $outsider = User::factory()->create(['role' => 'buyer']);
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertForbidden();
    }

    public function test_cannot_terminate_consignment_with_active_lots(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $gallery = $this->makeGallery();
        $consignment = $this->makeConsignment($artwork, $owner, $gallery, status: 'active');

        // Attach an active lot
        ArtLot::create([
            'artwork_id'      => $artwork->id,
            'consignment_id'  => $consignment->id,
            'sale_mode'       => 'auction',
            'status'          => 'active',
            'currency'        => 'EUR',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertUnprocessable();
    }

    public function test_admin_can_terminate_any_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $admin = User::factory()->create(['role' => 'admin']);
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/consignments/{$consignment->id}/terminate")
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');
    }

    // ────────────────────────────────────────────────────────────────────────
    // F. Visibility — index/show scoped to caller
    // ────────────────────────────────────────────────────────────────────────

    public function test_owner_sees_own_consignments_in_index(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        [$other, $otherArtwork] = $this->makeOwnerAndArtwork();

        Consignment::create(['artwork_id' => $artwork->id,      'owner_id' => $owner->id, 'consignor_id' => $owner->id, 'commission_bps' => 0, 'status' => 'draft']);
        Consignment::create(['artwork_id' => $otherArtwork->id, 'owner_id' => $other->id, 'consignor_id' => $other->id, 'commission_bps' => 0, 'status' => 'draft']);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/consignments')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $ownIds = Consignment::where('owner_id', $owner->id)->pluck('id');
        $this->assertTrue($ids->intersect($ownIds)->isNotEmpty());
    }

    public function test_outsider_cannot_read_another_users_consignment(): void
    {
        [$owner, $artwork] = $this->makeOwnerAndArtwork();
        $outsider = User::factory()->create(['role' => 'buyer']);
        $consignment = Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/consignments/{$consignment->id}")
            ->assertForbidden();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /** @return array{User, Artwork} */
    private function makeOwnerAndArtwork(): array
    {
        $owner   = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'      => $owner->id,
            'title'        => 'Phase70 Work ' . uniqid(),
            'slug'         => 'phase70-' . uniqid(),
            'status'       => 'listed',
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);
        return [$owner, $artwork];
    }

    private function makeGallery(): Gallery
    {
        return Gallery::create([
            'name'   => 'Phase70 Gallery ' . uniqid(),
            'slug'   => 'p70-gallery-' . uniqid(),
            'status' => 'active',
        ]);
    }

    private function addStaff(Gallery $gallery, string $role): User
    {
        $user = User::factory()->create(['role' => 'artist']);
        GalleryStaff::create([
            'gallery_id' => $gallery->id,
            'user_id'    => $user->id,
            'role'       => $role,
            'status'     => 'active',
        ]);
        return $user;
    }

    private function makeConsignment(
        Artwork $artwork,
        User    $owner,
        Gallery $gallery,
        string  $status = 'draft',
    ): Consignment {
        return Consignment::create([
            'artwork_id'     => $artwork->id,
            'owner_id'       => $owner->id,
            'consignor_id'   => $owner->id,
            'gallery_id'     => $gallery->id,
            'commission_bps' => 1500,
            'status'         => $status,
        ]);
    }
}
