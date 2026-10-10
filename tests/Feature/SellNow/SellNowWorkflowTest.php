<?php

declare(strict_types=1);

namespace Tests\Feature\SellNow;

use App\Domain\SellNow\AcceptOffer;
use App\Domain\SellNow\CounterOffer;
use App\Domain\SellNow\PurchaseAtFixedPrice;
use App\Domain\SellNow\RejectOffer;
use App\Domain\SellNow\SubmitOffer;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Gallery;
use App\Models\SellNowOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SellNowWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User   $seller;
    private User   $buyer;
    private ArtLot $artLot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'artist']);
        $this->buyer  = User::factory()->create(['role' => 'buyer']);

        $artwork = Artwork::create([
            'user_id' => $this->seller->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->artLot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'consignor_id'       => $this->seller->id,
            'sale_mode'          => 'sell_now',
            'status'             => 'active',
            'buy_now_price_cents' => 50000,
            'currency'           => 'EUR',
        ]);
    }

    // ── Gallery model ──────────────────────────────────────────────────────────

    public function test_gallery_stores_all_fields(): void
    {
        $gallery = Gallery::create([
            'name'             => 'Sofia Modern Gallery',
            'slug'             => 'sofia-modern',
            'type'             => 'private',
            'legal_name'       => 'Sofia Modern OOD',
            'eik'              => '123456789',
            'contact_email'    => 'info@sofiamodern.bg',
            'stripe_account_id' => 'acct_test123',
            'status'           => 'active',
        ]);

        $this->assertDatabaseHas('galleries', [
            'slug'   => 'sofia-modern',
            'type'   => 'private',
            'status' => 'active',
        ]);
        $this->assertTrue($gallery->isActive());
    }

    public function test_gallery_has_many_art_lots(): void
    {
        $gallery = Gallery::create([
            'name'   => 'Test Gallery',
            'slug'   => 'test-gallery',
            'status' => 'active',
        ]);

        $this->artLot->update(['gallery_id' => $gallery->id]);

        $this->assertCount(1, $gallery->artLots);
        $this->assertEquals($gallery->id, $this->artLot->fresh()->gallery_id);
    }

    public function test_art_lot_belongs_to_gallery(): void
    {
        $gallery = Gallery::create([
            'name'   => 'Test Gallery',
            'slug'   => 'test-gallery-2',
            'status' => 'active',
        ]);

        $this->artLot->update(['gallery_id' => $gallery->id]);

        $this->assertEquals('Test Gallery', $this->artLot->fresh()->gallery->name);
    }

    // ── SubmitOffer ────────────────────────────────────────────────────────────

    public function test_submit_offer_creates_record(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);

        $this->assertInstanceOf(SellNowOffer::class, $offer);
        $this->assertEquals('submitted', $offer->status);
        $this->assertEquals(40000, $offer->offered_price_cents);
        $this->assertEquals($this->artLot->id, $offer->art_lot_id);
        $this->assertEquals($this->buyer->id, $offer->buyer_id);
    }

    public function test_submit_offer_rejects_unavailable_lot(): void
    {
        $this->artLot->update(['status' => 'sold']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not available for offers|status: sold/');

        (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
    }

    public function test_submit_offer_rejects_non_positive_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        (new SubmitOffer())->execute($this->artLot, $this->buyer, 0);
    }

    public function test_submit_offer_with_gallery(): void
    {
        $gallery = Gallery::create(['name' => 'G', 'slug' => 'g-' . uniqid(), 'status' => 'active']);

        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 30000, $gallery->id);

        $this->assertEquals($gallery->id, $offer->gallery_id);
    }

    // ── CounterOffer ───────────────────────────────────────────────────────────

    public function test_counter_offer_updates_status(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);

        $countered = (new CounterOffer())->execute($offer, 45000);

        $this->assertEquals('countered', $countered->status);
        $this->assertEquals(45000, $countered->counter_price_cents);
    }

    public function test_counter_offer_rejects_non_submitted_status(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
        (new RejectOffer())->execute($offer);

        $this->expectException(InvalidArgumentException::class);

        (new CounterOffer())->execute($offer->fresh(), 45000);
    }

    // ── AcceptOffer ────────────────────────────────────────────────────────────

    public function test_accept_submitted_offer_uses_offered_price(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);

        $accepted = (new AcceptOffer())->execute($offer);

        $this->assertEquals('accepted', $accepted->status);
        $this->assertEquals(40000, $accepted->agreed_price_cents);
    }

    public function test_accept_countered_offer_uses_counter_price(): void
    {
        $offer    = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
        $countered = (new CounterOffer())->execute($offer, 45000);

        $accepted = (new AcceptOffer())->execute($countered);

        $this->assertEquals('accepted', $accepted->status);
        $this->assertEquals(45000, $accepted->agreed_price_cents);
    }

    public function test_accept_rejects_already_rejected(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
        (new RejectOffer())->execute($offer);

        $this->expectException(InvalidArgumentException::class);

        (new AcceptOffer())->execute($offer->fresh());
    }

    // ── RejectOffer ────────────────────────────────────────────────────────────

    public function test_reject_offer_sets_status(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);

        $rejected = (new RejectOffer())->execute($offer, 'Not the right price.');

        $this->assertEquals('rejected', $rejected->status);
        $this->assertEquals('Not the right price.', $rejected->notes);
    }

    public function test_reject_countered_offer(): void
    {
        $offer    = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
        $countered = (new CounterOffer())->execute($offer, 45000);

        $rejected = (new RejectOffer())->execute($countered);

        $this->assertEquals('rejected', $rejected->status);
    }

    public function test_reject_already_accepted_throws(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
        (new AcceptOffer())->execute($offer);

        $this->expectException(InvalidArgumentException::class);

        (new RejectOffer())->execute($offer->fresh());
    }

    // ── PurchaseAtFixedPrice ───────────────────────────────────────────────────

    public function test_purchase_at_fixed_price_creates_accepted_offer(): void
    {
        $offer = (new PurchaseAtFixedPrice())->execute($this->artLot, $this->buyer);

        $this->assertEquals('accepted', $offer->status);
        $this->assertEquals(50000, $offer->offered_price_cents);
        $this->assertEquals(50000, $offer->agreed_price_cents);
    }

    public function test_purchase_at_fixed_price_rejects_unavailable_lot(): void
    {
        $this->artLot->update(['status' => 'sold']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not available|status: sold/');

        (new PurchaseAtFixedPrice())->execute($this->artLot->fresh(), $this->buyer);
    }

    public function test_purchase_at_fixed_price_rejects_lot_with_no_price(): void
    {
        $this->artLot->update(['buy_now_price_cents' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no fixed buy-now price');

        (new PurchaseAtFixedPrice())->execute($this->artLot->fresh(), $this->buyer);
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function test_sell_now_offer_belongs_to_art_lot_and_buyer(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);

        $this->assertEquals($this->artLot->id, $offer->artLot->id);
        $this->assertEquals($this->buyer->id, $offer->buyer->id);
    }

    public function test_art_lot_has_many_sell_now_offers(): void
    {
        $buyer2 = User::factory()->create(['role' => 'buyer']);
        (new SubmitOffer())->execute($this->artLot, $this->buyer, 38000);
        (new SubmitOffer())->execute($this->artLot, $buyer2, 40000);

        $this->assertCount(2, $this->artLot->sellNowOffers);
    }

    public function test_is_pending_and_is_terminal(): void
    {
        $offer = (new SubmitOffer())->execute($this->artLot, $this->buyer, 40000);
        $this->assertTrue($offer->isPending());
        $this->assertFalse($offer->isTerminal());

        $accepted = (new AcceptOffer())->execute($offer);
        $this->assertFalse($accepted->isPending());
        $this->assertTrue($accepted->isTerminal());
    }
}
