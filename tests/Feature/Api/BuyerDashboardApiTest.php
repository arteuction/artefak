<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for buyer-scoped dashboard endpoints:
 *   GET /api/v1/my/won-items
 *   GET /api/v1/my/bids
 *   GET /api/v1/auction-items/{item}/bid-history
 */
final class BuyerDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    /**
     * Insert an auction_item row, bypassing FK constraints.
     * Returns the new row's id.
     */
    private function auctionItem(string $status = 'open'): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('auction_items')->insertGetId([
            'auction_id'       => 1,
            'art_lot_id'       => 1,
            'lot_number'         => 1,
            'status'             => $status,
            'fulfillment_status' => 'none',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        return $id;
    }

    /**
     * Insert a bid row for the given auction item and user.
     */
    private function bid(int $auctionItemId, int $userId, int $amountCents = 50000, string $status = 'pending'): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('bids')->insert([
            'auction_item_id' => $auctionItemId,
            'user_id'         => $userId,
            'amount_cents'    => $amountCents,
            'status'          => $status,
            'bid_type'        => 'live',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        Schema::enableForeignKeyConstraints();
    }

    // ── GET /my/won-items ─────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_view_won_items(): void
    {
        $this->getJson('/api/v1/my/won-items')->assertStatus(401);
    }

    public function test_buyer_can_view_won_items_endpoint(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/my/won-items')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page']]);
    }

    public function test_won_items_returns_empty_for_buyer_with_no_wins(): void
    {
        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/my/won-items')
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
    }

    // ── GET /my/bids ──────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_view_own_bids(): void
    {
        $this->getJson('/api/v1/my/bids')->assertStatus(401);
    }

    public function test_buyer_can_view_own_bids_endpoint(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/my/bids')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page']]);
    }

    public function test_my_bids_returns_empty_for_buyer_with_no_bids(): void
    {
        $response = $this->actingAs($this->buyer())
            ->getJson('/api/v1/my/bids')
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_my_bids_returns_only_own_bids(): void
    {
        $buyer1    = $this->buyer();
        $buyer2    = $this->buyer();
        $itemId    = $this->auctionItem('sold');

        $this->bid($itemId, $buyer1->id, 50000, 'outbid');
        $this->bid($itemId, $buyer2->id, 60000, 'won');

        $response = $this->actingAs($buyer1)
            ->getJson('/api/v1/my/bids')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    // ── GET /auction-items/{item}/bid-history ─────────────────────────────────

    public function test_unauthenticated_bid_history_on_open_item_returns_empty(): void
    {
        $itemId = $this->auctionItem('open');

        $response = $this->getJson("/api/v1/auction-items/{$itemId}/bid-history")
            ->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_bid_history_on_closed_item_shows_all_bids(): void
    {
        $buyer  = $this->buyer();
        $itemId = $this->auctionItem('sold');

        $this->bid($itemId, $buyer->id, 50000, 'outbid');

        $response = $this->getJson("/api/v1/auction-items/{$itemId}/bid-history")
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_bid_history_does_not_expose_user_id_after_close(): void
    {
        $buyer  = $this->buyer();
        $itemId = $this->auctionItem('sold');

        $this->bid($itemId, $buyer->id, 50000, 'outbid');

        $response = $this->getJson("/api/v1/auction-items/{$itemId}/bid-history")
            ->assertOk();

        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    public function test_authenticated_buyer_sees_only_own_bids_on_open_item(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $itemId = $this->auctionItem('open');

        $this->bid($itemId, $buyer1->id, 50000, 'active');
        $this->bid($itemId, $buyer2->id, 60000, 'active');

        $response = $this->actingAs($buyer1)
            ->getJson("/api/v1/auction-items/{$itemId}/bid-history")
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_bid_history_hides_user_id_on_live_item(): void
    {
        $buyer  = $this->buyer();
        $itemId = $this->auctionItem('open');

        $this->bid($itemId, $buyer->id, 75000, 'active');

        $response = $this->actingAs($buyer)
            ->getJson("/api/v1/auction-items/{$itemId}/bid-history")
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }
}
