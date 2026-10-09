<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 55: Buyer dashboard — won items, bid history, bid-history per item.
 */
final class Phase55ApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create();
    }

    private function makeAuctionItem(string $itemStatus = 'sold'): AuctionItem
    {
        $artist = User::factory()->create();
        DB::table('users')->where('id', $artist->id)->update(['role' => 'artist']);

        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Test Artwork ' . uniqid(),
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

        $auction = Auction::create([
            'title'     => 'Test Auction ' . uniqid(),
            'slug'      => 'auction-' . uniqid(),
            'status'    => 'closed',
            'currency'  => 'BGN',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->subMinutes(5),
        ]);

        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => $itemStatus,
        ]);
    }

    private function placeBid(AuctionItem $item, User $user, int $cents, string $status = 'accepted'): Bid
    {
        return Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $user->id,
            'amount_cents'    => $cents,
            'status'          => $status,
        ]);
    }

    // ── Won items ─────────────────────────────────────────────────────────────

    public function test_won_items_returns_items_with_won_bid(): void
    {
        $buyer = $this->buyer();
        $item  = $this->makeAuctionItem('sold');
        $bid   = $this->placeBid($item, $buyer, 15000, 'won');
        $item->update(['winning_bid_id' => $bid->id]);

        $response = $this->actingAs($buyer)->getJson('/api/v1/my/won-items');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($item->id, $ids);
    }

    public function test_won_items_excludes_other_users_wins(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $item   = $this->makeAuctionItem('sold');
        $bid    = $this->placeBid($item, $buyer2, 15000, 'won');
        $item->update(['winning_bid_id' => $bid->id]);

        $response = $this->actingAs($buyer1)->getJson('/api/v1/my/won-items');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($item->id, $ids);
    }

    public function test_won_items_requires_auth(): void
    {
        $this->getJson('/api/v1/my/won-items')->assertUnauthorized();
    }

    // ── My bids ───────────────────────────────────────────────────────────────

    public function test_my_bids_returns_caller_bids_only(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $item   = $this->makeAuctionItem('open');
        $bid1   = $this->placeBid($item, $buyer1, 12000);
        $bid2   = $this->placeBid($item, $buyer2, 13000);

        $response = $this->actingAs($buyer1)->getJson('/api/v1/my/bids');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($bid1->id, $ids);
        $this->assertNotContains($bid2->id, $ids);
    }

    public function test_my_bids_requires_auth(): void
    {
        $this->getJson('/api/v1/my/bids')->assertUnauthorized();
    }

    // ── Bid history per item ──────────────────────────────────────────────────

    public function test_bid_history_returns_all_bids_for_closed_item(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $item   = $this->makeAuctionItem('sold');
        $bid1   = $this->placeBid($item, $buyer1, 12000, 'outbid');
        $bid2   = $this->placeBid($item, $buyer2, 15000, 'won');

        $response = $this->actingAs($buyer1)->getJson("/api/v1/auction-items/{$item->id}/bid-history");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($bid1->id, $ids);
        $this->assertContains($bid2->id, $ids);
        // No user_id in response (privacy)
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    public function test_bid_history_for_live_item_shows_only_callers_bids(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $item   = $this->makeAuctionItem('open');
        $bid1   = $this->placeBid($item, $buyer1, 12000);
        $bid2   = $this->placeBid($item, $buyer2, 15000);

        $response = $this->actingAs($buyer1)->getJson("/api/v1/auction-items/{$item->id}/bid-history");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($bid1->id, $ids);
        $this->assertNotContains($bid2->id, $ids);
    }

    public function test_bid_history_pagination_meta(): void
    {
        $buyer = $this->buyer();
        $item  = $this->makeAuctionItem('sold');
        $this->placeBid($item, $buyer, 10000, 'outbid');

        $this->actingAs($buyer)->getJson("/api/v1/auction-items/{$item->id}/bid-history")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page']]);
    }
}
