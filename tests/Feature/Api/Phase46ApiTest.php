<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46: Admin Auction CRUD and auction item management.
 */
final class Phase46ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeArtLot(User $artist, string $status = 'draft'): ArtLot
    {
        $artwork = Artwork::create([
            'user_id'    => $artist->id,
            'title'      => 'Art ' . uniqid(),
            'slug'       => 'art-' . uniqid(),
            'status'     => 'draft',
            'is_original'=> true,
        ]);

        return ArtLot::create([
            'artwork_id'    => $artwork->id,
            'consignor_id'  => $artist->id,
            'title'         => 'Lot ' . uniqid(),
            'slug'          => 'lot-' . uniqid(),
            'status'        => $status,
            'sale_mode'     => 'auction',
        ]);
    }

    // ── Auction create ────────────────────────────────────────────────────────

    public function test_admin_can_create_auction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/auctions', [
                'title'     => 'Spring Auction 2027',
                'starts_at' => '2027-03-01 10:00:00',
                'ends_at'   => '2027-03-01 18:00:00',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Spring Auction 2027');
    }

    public function test_non_admin_cannot_create_auction(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/admin/auctions', [
                'title'    => 'Sneaky Auction',
                'starts_at'=> '2027-03-01 10:00:00',
                'ends_at'  => '2027-03-01 18:00:00',
            ])
            ->assertForbidden();
    }

    // ── Auction update ────────────────────────────────────────────────────────

    public function test_admin_can_update_draft_auction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $auction = Auction::create([
            'title'    => 'Old Title',
            'slug'     => 'old-' . uniqid(),
            'status'   => 'draft',
            'currency' => 'BGN',
            'starts_at'=> now()->addDays(10),
            'ends_at'  => now()->addDays(11),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", [
                'title'  => 'New Title',
                'status' => 'published',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title')
            ->assertJsonPath('data.status', 'published');
    }

    public function test_cannot_update_live_auction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $auction = Auction::create([
            'title'    => 'Live Auction',
            'slug'     => 'live-' . uniqid(),
            'status'   => 'live',
            'currency' => 'BGN',
            'starts_at'=> now()->subHour(),
            'ends_at'  => now()->addHour(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'Hacked'])
            ->assertStatus(422);
    }

    // ── Auction items ─────────────────────────────────────────────────────────

    public function test_admin_can_add_item_to_draft_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artist  = User::factory()->create(['role' => 'artist']);
        $artLot  = $this->makeArtLot($artist, 'draft');
        $auction = Auction::create([
            'title' => 'Auction A', 'slug' => 'auction-' . uniqid(),
            'status' => 'draft', 'currency' => 'BGN',
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/items", [
                'art_lot_id'          => $artLot->id,
                'lot_number'          => 1,
                'bid_increment_cents' => 500,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.auction_id', $auction->id)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_cannot_add_duplicate_lot_to_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artist  = User::factory()->create(['role' => 'artist']);
        $artLot  = $this->makeArtLot($artist, 'draft');
        $auction = Auction::create([
            'title' => 'Auction B', 'slug' => 'auction-b-' . uniqid(),
            'status' => 'draft', 'currency' => 'BGN',
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6),
        ]);

        AuctionItem::create([
            'auction_id' => $auction->id, 'art_lot_id' => $artLot->id,
            'lot_number' => 1, 'bid_increment_cents' => 500, 'status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/items", [
                'art_lot_id'          => $artLot->id,
                'lot_number'          => 2,
                'bid_increment_cents' => 500,
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_remove_pending_item(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artist  = User::factory()->create(['role' => 'artist']);
        $artLot  = $this->makeArtLot($artist);
        $auction = Auction::create([
            'title' => 'Auction C', 'slug' => 'auction-c-' . uniqid(),
            'status' => 'draft', 'currency' => 'BGN',
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6),
        ]);
        $item = AuctionItem::create([
            'auction_id' => $auction->id, 'art_lot_id' => $artLot->id,
            'lot_number' => 1, 'bid_increment_cents' => 500, 'status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/auctions/{$auction->id}/items/{$item->id}")
            ->assertNoContent();
    }
}
