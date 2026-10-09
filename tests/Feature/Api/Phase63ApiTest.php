<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 63: Admin user management + Admin auction lifecycle.
 */
final class Phase63ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Admin user management ─────────────────────────────────────────────────

    public function test_admin_can_list_users(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'buyer']);
        User::factory()->create(['role' => 'artist']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        $this->assertGreaterThanOrEqual(3, $response->json('total'));
    }

    public function test_admin_can_filter_users_by_role(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'buyer']);
        User::factory()->create(['role' => 'artist']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/users?role=artist')
            ->assertOk();

        foreach ($response->json('data') as $u) {
            $this->assertSame('artist', $u['role']);
        }
    }

    public function test_admin_can_show_single_user(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $buyer  = User::factory()->create(['role' => 'buyer']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users/{$buyer->id}")
            ->assertOk();

        $this->assertSame($buyer->id, $response->json('data.id'));
        $this->assertSame('buyer', $response->json('data.role'));
    }

    public function test_admin_can_update_user_role(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $buyer  = User::factory()->create(['role' => 'buyer']);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$buyer->id}", ['role' => 'artist'])
            ->assertOk();

        $this->assertSame('artist', $response->json('data.role'));
        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'role' => 'artist']);
    }

    public function test_user_role_update_validates_enum(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$buyer->id}", ['role' => 'superuser'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $buyer  = User::factory()->create(['role' => 'buyer']);
        $other  = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$other->id}", ['role' => 'artist'])
            ->assertForbidden();
    }

    // ── Admin auction lifecycle ───────────────────────────────────────────────

    private function makeArtLot(): ArtLot
    {
        $artist  = User::factory()->create();
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Lot Artwork ' . uniqid(),
            'slug'         => 'lot-artwork-' . uniqid(),
            'status'       => 'listed',
            'year_created' => 2023,
            'medium'       => 'painting',
        ]);

        return ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'draft',
            'starting_bid_cents' => 5_000,
            'currency'           => 'EUR',
        ]);
    }

    public function test_admin_can_create_auction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/auctions', [
                'title'     => 'Spring Auction 2026',
                'starts_at' => now()->addDays(7)->toDateTimeString(),
                'ends_at'   => now()->addDays(14)->toDateTimeString(),
                'currency'  => 'BGN',
            ])
            ->assertCreated();

        $this->assertSame('Spring Auction 2026', $response->json('data.title'));
        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_admin_cannot_update_live_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $auction = Auction::create([
            'title'     => 'Live Auction',
            'slug'      => 'live-auction-' . uniqid(),
            'status'    => 'live',
            'currency'  => 'BGN',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/auctions/{$auction->id}", ['title' => 'New Title'])
            ->assertUnprocessable();
    }

    public function test_admin_can_add_lot_to_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artLot  = $this->makeArtLot();
        $auction = Auction::create([
            'title'     => 'Auction With Item',
            'slug'      => 'auction-with-item-' . uniqid(),
            'status'    => 'draft',
            'currency'  => 'BGN',
            'starts_at' => now()->addDays(5),
            'ends_at'   => now()->addDays(10),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/items", [
                'art_lot_id'          => $artLot->id,
                'lot_number'          => 1,
                'bid_increment_cents' => 500,
            ])
            ->assertCreated();

        $this->assertSame($artLot->id, $response->json('data.art_lot_id'));
        $this->assertSame('pending', $response->json('data.status'));
    }

    public function test_cannot_add_duplicate_lot_to_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artLot  = $this->makeArtLot();
        $auction = Auction::create([
            'title'     => 'Duplicate Test',
            'slug'      => 'dup-test-' . uniqid(),
            'status'    => 'draft',
            'currency'  => 'BGN',
            'starts_at' => now()->addDays(5),
            'ends_at'   => now()->addDays(10),
        ]);
        AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/items", [
                'art_lot_id'          => $artLot->id,
                'lot_number'          => 2,
                'bid_increment_cents' => 500,
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_publish_auction_with_items(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artLot  = $this->makeArtLot();
        $auction = Auction::create([
            'title'     => 'Publishable Auction',
            'slug'      => 'pub-auction-' . uniqid(),
            'status'    => 'draft',
            'currency'  => 'BGN',
            'starts_at' => now()->addDays(5),
            'ends_at'   => now()->addDays(10),
        ]);
        AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_cannot_publish_auction_without_items(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $auction = Auction::create([
            'title'     => 'Empty Auction',
            'slug'      => 'empty-auction-' . uniqid(),
            'status'    => 'draft',
            'currency'  => 'BGN',
            'starts_at' => now()->addDays(5),
            'ends_at'   => now()->addDays(10),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertUnprocessable();
    }

    public function test_admin_can_open_published_auction(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $artLot  = $this->makeArtLot();
        $auction = Auction::create([
            'title'     => 'To Open',
            'slug'      => 'to-open-' . uniqid(),
            'status'    => 'published',
            'currency'  => 'BGN',
            'starts_at' => now()->subMinutes(5),
            'ends_at'   => now()->addHour(),
        ]);
        AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertOk()
            ->assertJsonPath('data.status', 'live');

        $this->assertDatabaseHas('auction_items', [
            'auction_id' => $auction->id,
            'status'     => 'open',
        ]);
    }
}
