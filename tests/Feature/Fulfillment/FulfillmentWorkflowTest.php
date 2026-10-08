<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\CloseSellNow;
use App\Domain\Fulfillment\ConfirmAuctionDelivery;
use App\Domain\Fulfillment\ConfirmSellNowDelivery;
use App\Domain\Fulfillment\ConfirmSellNowPayment;
use App\Domain\Fulfillment\ShipAuctionItem;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\AuctionFulfillment;
use App\Models\AuctionItem;
use App\Models\Bid;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\OwnershipTransfer;
use App\Models\SellNowOffer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FulfillmentWorkflowTest extends TestCase
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
            'title'   => 'Fulfillment Artwork',
            'slug'    => 'fulfillment-' . uniqid(),
            'status'  => 'in_auction',
        ]);

        $this->artLot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $this->seller->id,
            'sale_mode'    => 'auction',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);
    }

    // ── Auction fulfillment helpers ────────────────────────────────────────────

    private function makeAuctionItem(string $fulfillmentStatus = 'paid'): AuctionItem
    {
        $region   = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia-ff', 'code' => 'SOF']);
        $muni     = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-ff-m', 'code' => 'SOF01']);
        $locality = GeoLocality::create(['geo_municipality_id' => $muni->id, 'name' => 'Sofia', 'slug' => 'sofia-ff-l', 'ekatte' => '68134', 'type' => 'city']);
        $venue    = Venue::create(['name' => 'Hall', 'slug' => 'hall-ff-' . uniqid(), 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $auction = Auction::create([
            'title'     => 'Fulfillment Test',
            'slug'      => 'ff-' . uniqid(),
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->subMinute(),
            'status'    => 'closed',
            'currency'  => 'EUR',
        ]);

        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $this->artLot->id,
            'lot_number'          => 1,
            'status'              => 'sold',
            'fulfillment_status'  => $fulfillmentStatus,
            'winner_user_id'      => $this->buyer->id,
        ]);

        $bid = Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $this->buyer->id,
            'amount_cents'    => 30000,
            'status'          => 'won',
            'bid_type'        => 'live',
        ]);
        $item->update(['winning_bid_id' => $bid->id]);

        AuctionFulfillment::create([
            'auction_item_id'      => $item->id,
            'winner_user_id'       => $this->buyer->id,
            'shipping_name'        => 'Test Buyer',
            'shipping_line1'       => '1 Main St',
            'shipping_city'        => 'Sofia',
            'shipping_postal_code' => '1000',
            'shipping_country'     => 'BG',
        ]);

        return $item->fresh();
    }

    // ── ShipAuctionItem ────────────────────────────────────────────────────────

    public function test_ship_advances_fulfillment_to_shipped(): void
    {
        $item = $this->makeAuctionItem('paid');

        $fulfillment = (new ShipAuctionItem())->execute($item, 'Speedy', 'TRK-001');

        $this->assertEquals('Speedy', $fulfillment->carrier);
        $this->assertEquals('TRK-001', $fulfillment->tracking_number);
        $this->assertNotNull($fulfillment->shipped_at);
        $this->assertEquals('shipped', $item->fresh()->fulfillment_status);
    }

    public function test_ship_rejects_non_paid_status(): void
    {
        $item = $this->makeAuctionItem('awaiting_payment');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be paid/');

        (new ShipAuctionItem())->execute($item, 'Speedy', 'TRK-002');
    }

    public function test_ship_rejects_already_shipped(): void
    {
        $item = $this->makeAuctionItem('shipped');

        $this->expectException(InvalidArgumentException::class);

        (new ShipAuctionItem())->execute($item, 'DHL', 'TRK-003');
    }

    // ── ConfirmAuctionDelivery ─────────────────────────────────────────────────

    public function test_confirm_delivery_advances_to_delivered(): void
    {
        $item = $this->makeAuctionItem('shipped');

        $fulfillment = (new ConfirmAuctionDelivery())->execute($item);

        $this->assertNotNull($fulfillment->delivered_at);
        $this->assertEquals('delivered', $item->fresh()->fulfillment_status);
    }

    public function test_confirm_delivery_records_ownership_transfer(): void
    {
        $item = $this->makeAuctionItem('shipped');

        (new ConfirmAuctionDelivery())->execute($item);

        $this->assertDatabaseHas('ownership_transfers', [
            'art_lot_id'      => $this->artLot->id,
            'to_user_id'      => $this->buyer->id,
            'auction_item_id' => $item->id,
            'channel'         => 'auction',
        ]);
    }

    public function test_confirm_delivery_rejects_non_shipped(): void
    {
        $item = $this->makeAuctionItem('paid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be shipped/');

        (new ConfirmAuctionDelivery())->execute($item);
    }

    public function test_ownership_transfer_price_from_winning_bid(): void
    {
        $item = $this->makeAuctionItem('shipped');

        (new ConfirmAuctionDelivery())->execute($item);

        $transfer = OwnershipTransfer::where('auction_item_id', $item->id)->first();
        $this->assertEquals(30000, $transfer->transfer_price_cents);
    }

    // ── Sell Now fulfillment ───────────────────────────────────────────────────

    private function makeSellNowOffer(string $status): SellNowOffer
    {
        $artwork = Artwork::create([
            'user_id' => $this->seller->id,
            'title'   => 'SN Artwork',
            'slug'    => 'sn-' . uniqid(),
            'status'  => 'listed',
        ]);
        $artLot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'consignor_id'       => $this->seller->id,
            'sale_mode'          => 'sell_now',
            'status'             => 'active',
            'buy_now_price_cents' => 40000,
            'currency'           => 'EUR',
        ]);

        return SellNowOffer::create([
            'art_lot_id'          => $artLot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 40000,
            'agreed_price_cents'  => 40000,
            'currency'            => 'EUR',
            'status'              => $status,
        ]);
    }

    public function test_confirm_payment_advances_accepted_to_paid(): void
    {
        $offer = $this->makeSellNowOffer('accepted');

        $result = (new ConfirmSellNowPayment())->execute($offer);

        $this->assertEquals('paid', $result->status);
    }

    public function test_confirm_payment_rejects_non_accepted(): void
    {
        $offer = $this->makeSellNowOffer('submitted');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be accepted/');

        (new ConfirmSellNowPayment())->execute($offer);
    }

    public function test_confirm_delivery_advances_paid_to_delivered(): void
    {
        $offer = $this->makeSellNowOffer('paid');

        $result = (new ConfirmSellNowDelivery())->execute($offer);

        $this->assertEquals('delivered', $result->status);
    }

    public function test_confirm_delivery_rejects_non_paid(): void
    {
        $offer = $this->makeSellNowOffer('accepted');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be paid/');

        (new ConfirmSellNowDelivery())->execute($offer);
    }

    public function test_close_sell_now_advances_to_closed(): void
    {
        $offer = $this->makeSellNowOffer('delivered');

        $result = (new CloseSellNow())->execute($offer);

        $this->assertEquals('closed', $result->status);
    }

    public function test_close_marks_art_lot_sold(): void
    {
        $offer = $this->makeSellNowOffer('delivered');

        (new CloseSellNow())->execute($offer);

        $this->assertEquals('sold', $offer->artLot->fresh()->status);
    }

    public function test_close_records_ownership_transfer(): void
    {
        $offer = $this->makeSellNowOffer('delivered');

        (new CloseSellNow())->execute($offer);

        $this->assertDatabaseHas('ownership_transfers', [
            'sell_now_offer_id'    => $offer->id,
            'to_user_id'           => $this->buyer->id,
            'transfer_price_cents' => 40000,
            'channel'              => 'sell_now',
        ]);
    }

    public function test_close_rejects_non_delivered(): void
    {
        $offer = $this->makeSellNowOffer('paid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be delivered/');

        (new CloseSellNow())->execute($offer);
    }

    // ── OwnershipTransfer model ────────────────────────────────────────────────

    public function test_art_lot_has_many_ownership_transfers(): void
    {
        $item = $this->makeAuctionItem('shipped');
        (new ConfirmAuctionDelivery())->execute($item);

        $this->assertCount(1, $this->artLot->fresh()->ownershipTransfers);
    }

    public function test_ownership_transfer_relations(): void
    {
        $offer = $this->makeSellNowOffer('delivered');
        (new CloseSellNow())->execute($offer);

        $transfer = OwnershipTransfer::where('sell_now_offer_id', $offer->id)->first();

        $this->assertNotNull($transfer->to);
        $this->assertEquals($this->buyer->id, $transfer->to->id);
        $this->assertNotNull($transfer->sellNowOffer);
    }
}
