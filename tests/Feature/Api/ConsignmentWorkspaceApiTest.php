<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ConsignmentWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    private User        $owner;
    private User        $curator;
    private Gallery     $gallery;
    private Artwork     $artwork;
    private Consignment $consignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = User::factory()->create(['role' => 'artist']);
        $this->curator = User::factory()->create(['role' => 'artist']);
        $this->gallery = Gallery::create([
            'name'    => 'API Gallery',
            'slug'    => 'api-gallery-' . uniqid(),
            'user_id' => $this->curator->id,
        ]);

        GalleryStaff::create([
            'gallery_id'  => $this->gallery->id,
            'user_id'     => $this->curator->id,
            'role'        => 'curator',
            'status'      => 'active',
            'invited_at'  => now(),
            'accepted_at' => now(),
            'invited_by'  => $this->curator->id,
        ]);

        $this->artwork = Artwork::create([
            'user_id' => $this->owner->id,
            'title'   => 'API Artwork',
            'slug'    => 'api-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->owner->id,
            'gallery_id'     => $this->gallery->id,
            'commission_bps' => 1500,
            'status'         => 'draft',
        ]);
    }

    public function test_approve_consignment(): void
    {
        Sanctum::actingAs($this->curator);

        $this->postJson("/api/v1/consignments/{$this->consignment->id}/approve")
             ->assertOk()
             ->assertJsonPath('status', 'active');
    }

    public function test_request_changes(): void
    {
        Sanctum::actingAs($this->curator);

        $this->postJson("/api/v1/consignments/{$this->consignment->id}/request-changes", [
            'reason' => 'Missing provenance certificate',
        ])->assertOk()
          ->assertJsonPath('status', 'changes_requested');
    }

    public function test_request_changes_requires_reason(): void
    {
        Sanctum::actingAs($this->curator);

        $this->postJson("/api/v1/consignments/{$this->consignment->id}/request-changes", [])
             ->assertUnprocessable();
    }

    public function test_create_lot_from_active_consignment(): void
    {
        $this->consignment->update(['status' => 'active']);
        Sanctum::actingAs($this->curator);

        $this->postJson("/api/v1/consignments/{$this->consignment->id}/create-lot", [
            'sale_mode'           => 'auction',
            'starting_bid_cents'  => 50000,
        ])->assertCreated()
          ->assertJsonPath('sale_mode', 'auction')
          ->assertJsonPath('consignment_id', $this->consignment->id);
    }

    public function test_create_lot_from_draft_consignment_fails(): void
    {
        Sanctum::actingAs($this->curator);

        $this->postJson("/api/v1/consignments/{$this->consignment->id}/create-lot", [
            'sale_mode' => 'auction',
        ])->assertUnprocessable();
    }

    public function test_create_hybrid_lot_via_api(): void
    {
        $this->consignment->update(['status' => 'active']);
        Sanctum::actingAs($this->curator);

        $this->postJson("/api/v1/consignments/{$this->consignment->id}/create-lot", [
            'sale_mode'           => 'hybrid',
            'buy_now_price_cents' => 300000,
        ])->assertCreated()
          ->assertJsonPath('sale_mode', 'hybrid')
          ->assertJsonPath('buy_now_price_cents', 300000);
    }

    public function test_unauthenticated_cannot_approve(): void
    {
        $this->postJson("/api/v1/consignments/{$this->consignment->id}/approve")
             ->assertUnauthorized();
    }
}
