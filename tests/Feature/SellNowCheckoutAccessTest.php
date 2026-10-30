<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Gallery;
use App\Models\SellNowOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Access-control tests for POST /api/v1/sell-now-offers/{offer}/checkout-session.
 *
 * These tests verify authentication and ownership checks without calling Stripe.
 * Stripe integration is tested in separate integration/E2E tests.
 */
final class SellNowCheckoutAccessTest extends TestCase
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

    private function lot(User $consignor): ArtLot
    {
        $artwork = Artwork::create([
            'user_id'    => $consignor->id,
            'title'      => 'Art ' . uniqid(),
            'slug'       => Str::uuid()->toString(),
            'status'     => 'listed',
            'is_original' => true,
        ]);

        $gallery = Gallery::create([
            'name'   => 'G ' . uniqid(),
            'slug'   => 'g-' . uniqid(),
            'status' => 'active',
            'type'   => 'private',
        ]);

        return ArtLot::create([
            'artwork_id'   => $artwork->id,
            'gallery_id'   => $gallery->id,
            'consignor_id' => $consignor->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
        ]);
    }

    private function offer(User $buyer, ArtLot $lot, string $status = 'pending'): SellNowOffer
    {
        return SellNowOffer::create([
            'art_lot_id'   => $lot->id,
            'buyer_id'     => $buyer->id,
            'amount_cents' => 10000,
            'currency'     => 'EUR',
            'status'       => $status,
        ]);
    }

    // ── authentication ────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_create_checkout_session(): void
    {
        $buyer = $this->buyer();
        $lot   = $this->lot($this->artist());
        $offer = $this->offer($buyer, $lot, 'accepted');

        $this->postJson("/api/v1/sell-now-offers/{$offer->id}/checkout-session")
            ->assertStatus(401);
    }

    // ── ownership ─────────────────────────────────────────────────────────────

    public function test_non_buyer_of_offer_cannot_create_checkout_session(): void
    {
        $buyer    = $this->buyer();
        $intruder = $this->buyer();
        $lot      = $this->lot($this->artist());
        $offer    = $this->offer($buyer, $lot, 'accepted');

        $this->actingAs($intruder)
            ->postJson("/api/v1/sell-now-offers/{$offer->id}/checkout-session")
            ->assertStatus(403);
    }

    // ── status guard ──────────────────────────────────────────────────────────

    public function test_buyer_cannot_checkout_pending_offer(): void
    {
        $buyer = $this->buyer();
        $lot   = $this->lot($this->artist());
        $offer = $this->offer($buyer, $lot, 'pending');

        $this->actingAs($buyer)
            ->postJson("/api/v1/sell-now-offers/{$offer->id}/checkout-session")
            ->assertStatus(422);
    }

    public function test_buyer_cannot_checkout_rejected_offer(): void
    {
        $buyer = $this->buyer();
        $lot   = $this->lot($this->artist());
        $offer = $this->offer($buyer, $lot, 'rejected');

        $this->actingAs($buyer)
            ->postJson("/api/v1/sell-now-offers/{$offer->id}/checkout-session")
            ->assertStatus(422);
    }
}
