<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SellNowOfferApiTest extends TestCase
{
    use RefreshDatabase;

    private User   $seller;
    private User   $buyer;
    private ArtLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'artist']);
        $this->buyer  = User::factory()->create(['role' => 'buyer']);

        $artwork = Artwork::create([
            'user_id' => $this->seller->id,
            'title'   => 'Sell Art',
            'slug'    => 'sell-art-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->lot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $this->seller->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);
    }

    public function test_submit_offer_requires_auth(): void
    {
        $this->postJson("/api/v1/art-lots/{$this->lot->id}/sell-now-offers", [
            'offered_price_cents' => 50000,
        ])->assertUnauthorized();
    }

    public function test_submit_offer_creates_pending_offer(): void
    {
        $this->actingAs($this->buyer)
            ->postJson("/api/v1/art-lots/{$this->lot->id}/sell-now-offers", [
                'offered_price_cents' => 50000,
            ])
            ->assertCreated()
            ->assertJsonFragment(['status' => 'submitted', 'offered_price_cents' => 50000]);
    }

    public function test_submit_offer_validates_price(): void
    {
        $this->actingAs($this->buyer)
            ->postJson("/api/v1/art-lots/{$this->lot->id}/sell-now-offers", [
                'offered_price_cents' => 0,
            ])
            ->assertUnprocessable();
    }

    public function test_counter_offer_requires_auth(): void
    {
        $offer = \App\Models\SellNowOffer::create([
            'art_lot_id'          => $this->lot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 40000,
            'currency'            => 'EUR',
            'status'              => 'submitted',
        ]);

        $this->postJson("/api/v1/sell-now-offers/{$offer->id}/counter", [
            'counter_price_cents' => 45000,
        ])->assertUnauthorized();
    }

    public function test_seller_can_counter_offer(): void
    {
        $offer = \App\Models\SellNowOffer::create([
            'art_lot_id'          => $this->lot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 40000,
            'currency'            => 'EUR',
            'status'              => 'submitted',
        ]);

        $this->actingAs($this->seller)
            ->postJson("/api/v1/sell-now-offers/{$offer->id}/counter", [
                'counter_price_cents' => 45000,
            ])
            ->assertOk()
            ->assertJsonFragment(['status' => 'countered', 'counter_price_cents' => 45000]);
    }

    public function test_buyer_can_accept_countered_offer(): void
    {
        $offer = \App\Models\SellNowOffer::create([
            'art_lot_id'          => $this->lot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 40000,
            'counter_price_cents' => 45000,
            'currency'            => 'EUR',
            'status'              => 'countered',
        ]);

        $this->actingAs($this->buyer)
            ->postJson("/api/v1/sell-now-offers/{$offer->id}/accept")
            ->assertOk()
            ->assertJsonFragment(['status' => 'accepted']);
    }

    public function test_reject_offer(): void
    {
        $offer = \App\Models\SellNowOffer::create([
            'art_lot_id'          => $this->lot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 40000,
            'currency'            => 'EUR',
            'status'              => 'submitted',
        ]);

        $this->actingAs($this->seller)
            ->postJson("/api/v1/sell-now-offers/{$offer->id}/reject")
            ->assertOk()
            ->assertJsonFragment(['status' => 'rejected']);
    }
}
