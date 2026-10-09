<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Auction\PlaceBid;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Phase 66: First user-facing vertical slice — buyer dashboard + bidding.
 *
 * Covers the full flow a buyer needs for the MVP:
 *   1. Browse art lots (public catalogue)
 *   2. View art lot detail (bidding screen data)
 *   3. Place a bid on a live auction item (Stripe mocked via container)
 *   4. See all their bids (buyer dashboard)
 *   5. See their won items (buyer dashboard)
 *   6. Read bid history after auction closes (no bidder identity)
 *
 * Also guards the boundaries:
 *   - Unauthenticated users cannot bid
 *   - Bids on non-live auctions are rejected
 *   - Bid below minimum increment is rejected
 */
final class Phase66ApiTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // 1. Public art-lot catalogue
    // ────────────────────────────────────────────────────────────────────────

    public function test_art_lot_index_returns_active_lots(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $art    = $this->makeArtwork($artist->id, 'listed');

        ArtLot::create([
            'artwork_id'  => $art->id,
            'sale_mode'   => 'auction',
            'status'      => 'active',
            'currency'    => 'EUR',
            'starting_bid_cents' => 5000,
        ]);

        $response = $this->getJson('/api/v1/art-lots')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'sale_mode', 'status', 'currency', 'artwork'],
            ],
        ]);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_art_lot_index_excludes_draft_lots(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $art    = $this->makeArtwork($artist->id, 'draft');

        $draft = ArtLot::create([
            'artwork_id' => $art->id,
            'sale_mode'  => 'auction',
            'status'     => 'draft',
            'currency'   => 'EUR',
        ]);

        $response = $this->getJson('/api/v1/art-lots')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($draft->id, $ids);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Art-lot detail (bidding screen)
    // ────────────────────────────────────────────────────────────────────────

    public function test_art_lot_show_response_shape(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $art    = $this->makeArtwork($artist->id, 'in_auction');
        $lot    = ArtLot::create([
            'artwork_id'         => $art->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'currency'           => 'EUR',
            'starting_bid_cents' => 10000,
        ]);

        $response = $this->getJson("/api/v1/art-lots/{$lot->id}")->assertOk();

        $response->assertJsonStructure([
            'id', 'sale_mode', 'status', 'currency',
            'artwork' => ['id', 'title', 'slug'],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Bidding
    // ────────────────────────────────────────────────────────────────────────

    public function test_authenticated_buyer_can_place_bid(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot);

        $this->bindStripeMock('pi_test_phase66_bid');

        $response = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/auctions/{$auction->id}/items/{$item->id}/bids", [
                'amount_cents'      => 15000,
                'payment_method_id' => 'pm_card_visa',
            ])
            ->assertCreated();

        $response->assertJsonStructure(['id', 'amount_cents', 'status', 'auction_item_id']);
        $this->assertSame(15000, $response->json('amount_cents'));
        $this->assertSame('authorized', $response->json('payment_status'));
    }

    public function test_unauthenticated_user_cannot_place_bid(): void
    {
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot);

        $this->postJson("/api/auctions/{$auction->id}/items/{$item->id}/bids", [
            'amount_cents'      => 15000,
            'payment_method_id' => 'pm_card_visa',
        ])->assertUnauthorized();
    }

    public function test_bid_on_non_live_auction_is_rejected(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $artist  = User::factory()->create(['role' => 'artist']);
        $art     = $this->makeArtwork($artist->id, 'listed');
        $lot     = ArtLot::create([
            'artwork_id'         => $art->id,
            'sale_mode'          => 'auction',
            'status'             => 'draft',
            'currency'           => 'EUR',
            'starting_bid_cents' => 5000,
        ]);

        $auction = Auction::create([
            'title'     => 'Closed Auction',
            'slug'      => 'closed-auction-' . uniqid(),
            'status'    => 'closed',
            'currency'  => 'EUR',
            'starts_at' => now()->subDays(3),
            'ends_at'   => now()->subDay(),
        ]);

        $item = AuctionItem::create([
            'auction_id'  => $auction->id,
            'art_lot_id'  => $lot->id,
            'lot_number'  => 1,
            'status'      => 'sold',
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/auctions/{$auction->id}/items/{$item->id}/bids", [
                'amount_cents'      => 5000,
                'payment_method_id' => 'pm_card_visa',
            ])
            ->assertUnprocessable();
    }

    public function test_bid_below_starting_price_is_rejected(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot(startingBid: 10000);
        $item    = $this->makeOpenItem($auction, $lot);

        $this->bindStripeMock('pi_test_phase66_low');

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/auctions/{$auction->id}/items/{$item->id}/bids", [
                'amount_cents'      => 500,  // below starting bid of 10000
                'payment_method_id' => 'pm_card_visa',
            ])
            ->assertUnprocessable();
    }

    public function test_bid_requires_payment_method_starting_with_pm(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/auctions/{$auction->id}/items/{$item->id}/bids", [
                'amount_cents'      => 15000,
                'payment_method_id' => 'invalid_payment_id',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method_id']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Buyer dashboard — my bids
    // ────────────────────────────────────────────────────────────────────────

    public function test_my_bids_response_shape(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot);

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $buyer->id,
            'amount_cents'    => 15000,
            'status'          => 'pending',
        ]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/my/bids')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'amount_cents', 'status', 'auction_item_id'],
            ],
            'meta' => ['total', 'current_page', 'last_page'],
        ]);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_my_bids_only_returns_own_bids(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $other   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot);

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $buyer->id,
            'amount_cents'    => 15000,
            'status'          => 'pending',
        ]);
        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $other->id,
            'amount_cents'    => 20000,
            'status'          => 'pending',
        ]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/my/bids')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(15000, $response->json('data.0.amount_cents'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 5. Buyer dashboard — won items
    // ────────────────────────────────────────────────────────────────────────

    public function test_my_won_items_response_shape(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot, status: 'sold');

        $bid = Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $buyer->id,
            'amount_cents'    => 25000,
            'status'          => 'won',
        ]);
        $item->update(['winning_bid_id' => $bid->id]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/my/won-items')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'status', 'art_lot'],
            ],
            'meta' => ['total', 'current_page', 'last_page'],
        ]);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_my_won_items_does_not_include_lost_items(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $other   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction();
        $lot     = $this->makeActiveLot();
        $item    = $this->makeOpenItem($auction, $lot, status: 'sold');

        // other buyer wins
        $winBid = Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $other->id,
            'amount_cents'    => 30000,
            'status'          => 'won',
        ]);
        // buyer lost
        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $buyer->id,
            'amount_cents'    => 25000,
            'status'          => 'outbid',
        ]);
        $item->update(['winning_bid_id' => $winBid->id]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/my/won-items')
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 6. Bid history (post-auction)
    // ────────────────────────────────────────────────────────────────────────

    public function test_bid_history_returns_amounts_only_after_auction_closes(): void
    {
        $buyer   = User::factory()->create(['role' => 'buyer']);
        $auction = $this->makeLiveAuction(status: 'closed');
        $lot     = $this->makeActiveLot(status: 'sold');
        $item    = $this->makeOpenItem($auction, $lot, status: 'sold');

        Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $buyer->id,
            'amount_cents'    => 15000,
            'status'          => 'won',
        ]);

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/auction-items/{$item->id}/bid-history")
            ->assertOk();

        $this->assertNotEmpty($response->json('data'));
        foreach ($response->json('data') as $bid) {
            $this->assertArrayHasKey('amount_cents', $bid);
            $this->assertArrayNotHasKey('user_id', $bid);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function makeArtwork(int $userId, string $status): Artwork
    {
        return Artwork::create([
            'user_id'      => $userId,
            'title'        => 'Phase66 Artwork ' . uniqid(),
            'slug'         => 'phase66-art-' . uniqid(),
            'status'       => $status,
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);
    }

    private function makeLiveAuction(string $status = 'live'): Auction
    {
        return Auction::create([
            'title'     => 'Phase66 Auction ' . uniqid(),
            'slug'      => 'phase66-auction-' . uniqid(),
            'status'    => $status,
            'currency'  => 'EUR',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
        ]);
    }

    private function makeActiveLot(int $startingBid = 10000, string $status = 'active'): ArtLot
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $art    = $this->makeArtwork($artist->id, 'in_auction');

        return ArtLot::create([
            'artwork_id'         => $art->id,
            'sale_mode'          => 'auction',
            'status'             => $status,
            'currency'           => 'EUR',
            'starting_bid_cents' => $startingBid,
        ]);
    }

    private function makeOpenItem(Auction $auction, ArtLot $lot, string $status = 'open'): AuctionItem
    {
        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => $status,
        ]);
    }

    private function bindStripeMock(string $piId): void
    {
        $pi = $this->createMock(PaymentIntent::class);
        $pi->method('__get')->with('id')->willReturn($piId);

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willReturn($pi);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->app->instance(StripeClient::class, $stripe);
    }
}
