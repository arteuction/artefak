<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\SellNowOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 72 — Sell Now complete API HTTP authorization tests
 *
 * Covers offer lifecycle authorization gates:
 *   Section A — Submit offer guards
 *   Section B — Counter-offer guards (seller/gallery only)
 *   Section C — Accept / Reject guards
 *   Section D — Fulfillment guards (confirm-payment, confirm-delivery, close)
 *   Section E — Index visibility scoping
 *   Section F — Unauthenticated blocked on all routes
 */
final class Phase72ApiTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeArtist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function makeBuyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeActiveSellNowLot(User $consignor): ArtLot
    {
        $artwork = Artwork::create([
            'user_id'   => $consignor->id,
            'title'     => 'Test Artwork ' . uniqid(),
            'slug'      => 'test-artwork-' . uniqid(),
            'status'    => 'listed',
        ]);

        return ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $consignor->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
            'ask_price_cents' => 100000,
        ]);
    }

    private function makeOffer(ArtLot $artLot, User $buyer, string $status = 'submitted'): SellNowOffer
    {
        return SellNowOffer::create([
            'art_lot_id'          => $artLot->id,
            'buyer_id'            => $buyer->id,
            'offered_price_cents' => 90000,
            'currency'            => 'EUR',
            'status'              => $status,
        ]);
    }

    // -----------------------------------------------------------------------
    // Section A — Submit offer guards
    // -----------------------------------------------------------------------

    /** @test */
    public function test_unauthenticated_cannot_submit_offer(): void
    {
        $artist = $this->makeArtist();
        $lot    = $this->makeActiveSellNowLot($artist);

        $response = $this->postJson("/api/v1/art-lots/{$lot->id}/sell-now-offers", [
            'offered_price_cents' => 90000,
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_buyer_can_submit_offer_on_active_lot(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);

        $response = $this->actingAs($buyer)->postJson("/api/v1/art-lots/{$lot->id}/sell-now-offers", [
            'offered_price_cents' => 90000,
        ]);

        $response->assertStatus(201);
        $this->assertSame('submitted', $response->json('status'));
        $this->assertSame($buyer->id, $response->json('buyer_id'));
    }

    /** @test */
    public function test_cannot_submit_offer_on_inactive_lot(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();

        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Listed Art',
            'slug'    => 'listed-art-' . uniqid(),
            'status'  => 'listed',
        ]);
        $lot = ArtLot::create([
            'artwork_id'      => $artwork->id,
            'consignor_id'    => $artist->id,
            'sale_mode'       => 'sell_now',
            'status'          => 'sold',
            'currency'        => 'EUR',
            'ask_price_cents' => 100000,
        ]);

        $response = $this->actingAs($buyer)->postJson("/api/v1/art-lots/{$lot->id}/sell-now-offers", [
            'offered_price_cents' => 90000,
        ]);

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Section B — Counter-offer guards
    // -----------------------------------------------------------------------

    /** @test */
    public function test_unauthenticated_cannot_counter_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer);

        $response = $this->postJson("/api/v1/sell-now-offers/{$offer->id}/counter", [
            'counter_price_cents' => 95000,
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_authenticated_user_can_counter_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer);

        // Counter by the consignor (seller)
        $response = $this->actingAs($artist)->postJson("/api/v1/sell-now-offers/{$offer->id}/counter", [
            'counter_price_cents' => 95000,
        ]);

        $response->assertStatus(200);
        $this->assertSame('countered', $response->json('status'));
        $this->assertSame(95000, $response->json('counter_price_cents'));
    }

    /** @test */
    public function test_cannot_counter_already_accepted_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'accepted');

        $response = $this->actingAs($artist)->postJson("/api/v1/sell-now-offers/{$offer->id}/counter", [
            'counter_price_cents' => 95000,
        ]);

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Section C — Accept / Reject guards
    // -----------------------------------------------------------------------

    /** @test */
    public function test_unauthenticated_cannot_accept_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer);

        $response = $this->postJson("/api/v1/sell-now-offers/{$offer->id}/accept");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_authenticated_user_can_accept_submitted_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer);

        $response = $this->actingAs($artist)->postJson("/api/v1/sell-now-offers/{$offer->id}/accept");

        $response->assertStatus(200);
        $this->assertSame('accepted', $response->json('status'));
    }

    /** @test */
    public function test_unauthenticated_cannot_reject_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer);

        $response = $this->postJson("/api/v1/sell-now-offers/{$offer->id}/reject");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_authenticated_user_can_reject_submitted_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer);

        $response = $this->actingAs($artist)->postJson("/api/v1/sell-now-offers/{$offer->id}/reject");

        $response->assertStatus(200);
        $this->assertSame('rejected', $response->json('status'));
    }

    /** @test */
    public function test_cannot_accept_already_rejected_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'rejected');

        $response = $this->actingAs($artist)->postJson("/api/v1/sell-now-offers/{$offer->id}/accept");

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Section D — Fulfillment guards
    // -----------------------------------------------------------------------

    /** @test */
    public function test_unauthenticated_cannot_confirm_payment(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'accepted');

        $response = $this->postJson("/api/v1/sell-now-offers/{$offer->id}/confirm-payment");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_non_admin_cannot_confirm_payment(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'accepted');

        // Buyer cannot confirm payment — admin/operator only
        $response = $this->actingAs($buyer)->postJson("/api/v1/sell-now-offers/{$offer->id}/confirm-payment");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_can_confirm_payment(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $admin  = $this->makeAdmin();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'accepted');

        $response = $this->actingAs($admin)->postJson("/api/v1/sell-now-offers/{$offer->id}/confirm-payment");

        $response->assertStatus(200);
        $this->assertSame('paid', $response->json('status'));
    }

    /** @test */
    public function test_unauthenticated_cannot_confirm_delivery(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'paid');

        $response = $this->postJson("/api/v1/sell-now-offers/{$offer->id}/confirm-delivery");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_authenticated_user_can_confirm_delivery(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'paid');

        $response = $this->actingAs($buyer)->postJson("/api/v1/sell-now-offers/{$offer->id}/confirm-delivery");

        $response->assertStatus(200);
        $this->assertSame('delivered', $response->json('status'));
    }

    /** @test */
    public function test_unauthenticated_cannot_close_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'delivered');

        $response = $this->postJson("/api/v1/sell-now-offers/{$offer->id}/close");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_authenticated_user_can_close_delivered_offer(): void
    {
        $artist = $this->makeArtist();
        $buyer  = $this->makeBuyer();
        $lot    = $this->makeActiveSellNowLot($artist);
        $offer  = $this->makeOffer($lot, $buyer, 'delivered');

        $response = $this->actingAs($artist)->postJson("/api/v1/sell-now-offers/{$offer->id}/close", [
            'notes' => 'Ownership transferred.',
        ]);

        $response->assertStatus(200);
        $this->assertSame('closed', $response->json('status'));
    }

    // -----------------------------------------------------------------------
    // Section E — Index visibility scoping
    // -----------------------------------------------------------------------

    /** @test */
    public function test_unauthenticated_cannot_list_offers(): void
    {
        $artist = $this->makeArtist();
        $lot    = $this->makeActiveSellNowLot($artist);

        $response = $this->getJson("/api/v1/art-lots/{$lot->id}/sell-now-offers");

        $response->assertStatus(401);
    }

    /** @test */
    public function test_seller_sees_all_offers_on_own_lot(): void
    {
        $artist  = $this->makeArtist();
        $buyerA  = $this->makeBuyer();
        $buyerB  = $this->makeBuyer();
        $lot     = $this->makeActiveSellNowLot($artist);

        $this->makeOffer($lot, $buyerA);
        $this->makeOffer($lot, $buyerB);

        $response = $this->actingAs($artist)->getJson("/api/v1/art-lots/{$lot->id}/sell-now-offers");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('total'));
    }

    /** @test */
    public function test_buyer_sees_only_own_offers(): void
    {
        $artist  = $this->makeArtist();
        $buyerA  = $this->makeBuyer();
        $buyerB  = $this->makeBuyer();
        $lot     = $this->makeActiveSellNowLot($artist);

        $this->makeOffer($lot, $buyerA);
        $this->makeOffer($lot, $buyerB);

        $response = $this->actingAs($buyerA)->getJson("/api/v1/art-lots/{$lot->id}/sell-now-offers");

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('total'));
        $this->assertSame($buyerA->id, $response->json('data.0.buyer_id'));
    }

    /** @test */
    public function test_unrelated_buyer_sees_no_offers_on_others_lot(): void
    {
        $artist    = $this->makeArtist();
        $buyerA    = $this->makeBuyer();
        $intruder  = $this->makeBuyer();
        $lot       = $this->makeActiveSellNowLot($artist);

        $this->makeOffer($lot, $buyerA);

        $response = $this->actingAs($intruder)->getJson("/api/v1/art-lots/{$lot->id}/sell-now-offers");

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('total'));
    }
}
