<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 52: Auction lifecycle state-machine endpoints.
 *
 * draft → published → live → closed
 */
final class Phase52ApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);
        return $user->fresh();
    }

    private function buyer(): User
    {
        return User::factory()->create();
    }

    private function makeAuction(string $status = 'draft'): Auction
    {
        return Auction::create([
            'title'     => 'Test Auction',
            'slug'      => 'test-auction-' . uniqid(),
            'status'    => $status,
            'currency'  => 'BGN',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
        ]);
    }

    private function addItem(Auction $auction, string $itemStatus = 'pending'): AuctionItem
    {
        $artist = User::factory()->create();
        DB::table('users')->where('id', $artist->id)->update(['role' => 'artist']);

        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Artwork ' . uniqid(),
            'slug'         => 'artwork-' . uniqid(),
            'status'       => 'listed',
            'currency'     => 'BGN',
            'year_created' => 2022,
            'medium'       => 'painting',
        ]);

        $lot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 10000,
            'currency'           => 'BGN',
        ]);

        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => $itemStatus,
        ]);
    }

    // ── Publish ───────────────────────────────────────────────────────────────

    public function test_publish_transitions_draft_to_published(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('draft');
        $this->addItem($auction);

        $response = $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/publish");

        $response->assertOk();
        $this->assertSame('published', $response->json('data.status'));
        $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => 'published']);
    }

    public function test_publish_fails_if_not_draft(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('published');
        $this->addItem($auction);

        $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertUnprocessable();
    }

    public function test_publish_fails_without_items(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('draft');

        $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertUnprocessable();
    }

    public function test_publish_requires_admin(): void
    {
        $buyer   = $this->buyer();
        $auction = $this->makeAuction('draft');
        $this->addItem($auction);

        $this->actingAs($buyer)->postJson("/api/v1/admin/auctions/{$auction->id}/publish")
            ->assertForbidden();
    }

    // ── Open ──────────────────────────────────────────────────────────────────

    public function test_open_transitions_published_to_live_and_opens_items(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('published');
        $this->addItem($auction, 'pending');

        $response = $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/open");

        $response->assertOk();
        $this->assertSame('live', $response->json('data.status'));
        $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => 'live']);
        $this->assertDatabaseMissing('auction_items', ['auction_id' => $auction->id, 'status' => 'pending']);
        $this->assertDatabaseHas('auction_items', ['auction_id' => $auction->id, 'status' => 'open']);
    }

    public function test_open_fails_if_not_published(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('draft');

        $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertUnprocessable();
    }

    public function test_open_requires_admin(): void
    {
        $buyer   = $this->buyer();
        $auction = $this->makeAuction('published');
        $this->addItem($auction);

        $this->actingAs($buyer)->postJson("/api/v1/admin/auctions/{$auction->id}/open")
            ->assertForbidden();
    }

    // ── Close ─────────────────────────────────────────────────────────────────

    public function test_close_transitions_live_to_closed(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('live');
        $this->addItem($auction, 'open');

        $response = $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/close");

        $response->assertOk();
        $this->assertSame('closed', $response->json('data.status'));
        $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => 'closed']);
        // Item with no bids should become 'passed'
        $this->assertDatabaseHas('auction_items', ['auction_id' => $auction->id, 'status' => 'passed']);
    }

    public function test_close_fails_if_not_live(): void
    {
        $admin   = $this->admin();
        $auction = $this->makeAuction('published');

        $this->actingAs($admin)->postJson("/api/v1/admin/auctions/{$auction->id}/close")
            ->assertUnprocessable();
    }

    public function test_close_requires_admin(): void
    {
        $buyer   = $this->buyer();
        $auction = $this->makeAuction('live');

        $this->actingAs($buyer)->postJson("/api/v1/admin/auctions/{$auction->id}/close")
            ->assertForbidden();
    }
}
