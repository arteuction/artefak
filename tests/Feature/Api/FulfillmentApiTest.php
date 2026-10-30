<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HTTP guard tests for fulfillment endpoints:
 *   POST /api/v1/auction-items/{item}/ship
 *   POST /api/v1/auction-items/{item}/confirm-delivery
 *   POST /api/v1/sell-now-offers/{offer}/confirm-payment
 *   POST /api/v1/sell-now-offers/{offer}/confirm-delivery
 *   POST /api/v1/sell-now-offers/{offer}/close
 *
 * Domain actions (ShipAuctionItem, ConfirmAuctionDelivery, etc.) require
 * valid state transitions — these tests cover auth guards and validation
 * that fire before the domain action is invoked.
 */
final class FulfillmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function auctionItem(): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('auction_items')->insertGetId([
            'auction_id'     => 1,
            'artwork_id'         => 1,
            'lot_number'         => 1,
            'starting_bid_cents' => 10000,
            'status'      => 'sold',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    private function sellNowOffer(string $status = 'accepted'): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('sell_now_offers')->insertGetId([
            'art_lot_id'          => 1,
            'buyer_id'            => 1,
            'offered_price_cents' => 100000,
            'currency'            => 'EUR',
            'status'              => $status,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    // ── shipAuctionItem ───────────────────────────────────────────────────────

    public function test_ship_requires_authentication(): void
    {
        $this->postJson('/api/v1/auction-items/1/ship', [
            'carrier'         => 'DHL',
            'tracking_number' => 'TRACK123',
        ])->assertStatus(401);
    }

    public function test_ship_validates_carrier_required(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/auction-items/1/ship', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['carrier', 'tracking_number']);
    }

    // ── confirmAuctionDelivery ────────────────────────────────────────────────

    public function test_confirm_auction_delivery_requires_authentication(): void
    {
        $this->postJson('/api/v1/auction-items/1/confirm-delivery')
            ->assertStatus(401);
    }

    // ── confirmSellNowPayment (admin only) ────────────────────────────────────

    public function test_confirm_sell_now_payment_requires_authentication(): void
    {
        $this->postJson('/api/v1/sell-now-offers/1/confirm-payment')
            ->assertStatus(401);
    }

    public function test_buyer_cannot_confirm_sell_now_payment(): void
    {
        $offerId = $this->sellNowOffer('accepted');

        $this->actingAs($this->buyer())
            ->postJson("/api/v1/sell-now-offers/{$offerId}/confirm-payment")
            ->assertStatus(403);
    }

    // ── confirmSellNowDelivery ────────────────────────────────────────────────

    public function test_confirm_sell_now_delivery_requires_authentication(): void
    {
        $this->postJson('/api/v1/sell-now-offers/1/confirm-delivery')
            ->assertStatus(401);
    }

    // ── closeSellNow ──────────────────────────────────────────────────────────

    public function test_close_sell_now_requires_authentication(): void
    {
        $this->postJson('/api/v1/sell-now-offers/1/close')
            ->assertStatus(401);
    }
}
