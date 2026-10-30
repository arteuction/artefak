<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HTTP guard tests for Stripe-dependent endpoints:
 *   POST /api/v1/auction-items/{item}/re-authorize
 *   POST /api/v1/sell-now-offers/{offer}/checkout-session
 *
 * These tests cover only the HTTP-level guards and validation
 * before the Stripe API is reached. Actual Stripe integration is
 * tested in Stripe test-mode integration suites only.
 */
final class BidReAuthAndSellNowCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function auctionItem(): int
    {
        Schema::disableForeignKeyConstraints();
        $id = DB::table('auction_items')->insertGetId([
            'auction_id'         => 1,
            'artwork_id'         => 1,
            'lot_number'         => 1,
            'starting_bid_cents' => 10000,
            'status'             => 'open',
            'currency'   => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
        return $id;
    }

    // ── BidReAuthorizationController ─────────────────────────────────────────

    public function test_re_authorize_requires_authentication(): void
    {
        // Route requires auth — 401 without token
        // Use ID 999 to avoid needing a real auction item in DB
        $this->postJson('/api/v1/auction-items/999/re-authorize', [
            'payment_method_id' => 'pm_test_123',
        ])->assertStatus(401);
    }

    public function test_re_authorize_validates_payment_method_id_format(): void
    {
        $buyer  = $this->buyer();
        $itemId = $this->auctionItem();

        $this->actingAs($buyer)
            ->postJson("/api/v1/auction-items/{$itemId}/re-authorize", [
                'payment_method_id' => 'not-starting-with-pm',
            ])
            ->assertStatus(422);
    }

    // ── SellNowCheckoutController ─────────────────────────────────────────────

    public function test_checkout_session_requires_authentication(): void
    {
        $this->postJson('/api/v1/sell-now-offers/1/checkout-session')
            ->assertStatus(401);
    }

    public function test_checkout_session_returns_403_for_non_buyer(): void
    {
        $buyer  = $this->buyer();
        $artist = $this->artist();

        // Create a sell-now offer owned by artist, buyer trying to access it
        Schema::disableForeignKeyConstraints();
        $offerId = DB::table('sell_now_offers')->insertGetId([
            'art_lot_id'          => 1,
            'buyer_id'            => $artist->id,
            'offered_price_cents' => 100000,
            'currency'            => 'EUR',
            'status'              => 'accepted',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        // buyer (different user) tries to pay — 403
        $this->actingAs($buyer)
            ->postJson("/api/v1/sell-now-offers/{$offerId}/checkout-session")
            ->assertStatus(403);
    }

    public function test_checkout_session_returns_422_for_non_accepted_offer(): void
    {
        $buyer = $this->buyer();

        Schema::disableForeignKeyConstraints();
        $offerId = DB::table('sell_now_offers')->insertGetId([
            'art_lot_id'          => 1,
            'buyer_id'            => $buyer->id,
            'offered_price_cents' => 100000,
            'currency'            => 'EUR',
            'status'              => 'submitted',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        // Offer is not accepted — 422 before Stripe is called
        $this->actingAs($buyer)
            ->postJson("/api/v1/sell-now-offers/{$offerId}/checkout-session")
            ->assertStatus(422);
    }
}
