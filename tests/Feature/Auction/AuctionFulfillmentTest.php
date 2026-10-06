<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\ExpireAuctionWinnerPayment;
use App\Domain\Auction\FulfillAuctionItem;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class AuctionFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private AuctionItem  $item;
    private User         $winner;
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $auction = Auction::create([
            'title'     => 'P8 Auction',
            'slug'      => 'p8-auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->subMinute(),
            'status'    => 'closed',
            'currency'  => 'EUR',
        ]);

        $artwork = Artwork::create([
            'user_id' => User::factory()->create()->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork',
            'status'  => 'in_auction',
        ]);

        $artLot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'sold',
            'starting_bid_cents' => 5000,
            'currency'           => 'EUR',
        ]);

        $this->winner = User::factory()->create(['role' => 'buyer']);

        $this->item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $artLot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'sold',
            'fulfillment_status'  => 'paid',
        ]);

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')->willReturn(null);

        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')
            ->with('paymentIntents')
            ->willReturn($piService);
    }

    private function address(): array
    {
        return [
            'name'        => 'Ivan Ivanov',
            'line1'       => 'Vitosha 1',
            'city'        => 'Sofia',
            'postal_code' => '1000',
            'country'     => 'BG',
        ];
    }

    private function fulfillAction(): FulfillAuctionItem
    {
        return new FulfillAuctionItem();
    }

    private function expireAction(): ExpireAuctionWinnerPayment
    {
        return new ExpireAuctionWinnerPayment($this->stripe);
    }

    // ── Happy-path fulfillment ────────────────────────────────────────────

    public function test_create_record_advances_to_preparing(): void
    {
        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());

        $this->assertDatabaseHas('auction_items', [
            'id'                 => $this->item->id,
            'fulfillment_status' => 'preparing',
        ]);
    }

    public function test_create_record_stores_shipping_address(): void
    {
        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());

        $this->assertDatabaseHas('auction_fulfillments', [
            'auction_item_id'      => $this->item->id,
            'winner_user_id'       => $this->winner->id,
            'shipping_name'        => 'Ivan Ivanov',
            'shipping_city'        => 'Sofia',
            'shipping_country'     => 'BG',
        ]);
    }

    public function test_mark_shipped_records_tracking_number(): void
    {
        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());
        $this->item->refresh();

        $this->fulfillAction()->markShipped($this->item, 'Speedy', 'TRACK123');

        $this->assertDatabaseHas('auction_items', [
            'id'                 => $this->item->id,
            'fulfillment_status' => 'shipped',
        ]);

        $this->assertDatabaseHas('auction_fulfillments', [
            'auction_item_id' => $this->item->id,
            'carrier'         => 'Speedy',
            'tracking_number' => 'TRACK123',
        ]);
    }

    public function test_mark_delivered_closes_fulfillment(): void
    {
        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());
        $this->item->refresh();
        $this->fulfillAction()->markShipped($this->item, 'Speedy', 'TRACK123');
        $this->item->refresh();

        $this->fulfillAction()->markDelivered($this->item);

        $this->assertDatabaseHas('auction_items', [
            'id'                 => $this->item->id,
            'fulfillment_status' => 'delivered',
        ]);

        $fulfillment = \DB::table('auction_fulfillments')
            ->where('auction_item_id', $this->item->id)
            ->first();

        $this->assertNotNull($fulfillment->delivered_at);
    }

    // ── Idempotency ───────────────────────────────────────────────────────

    public function test_mark_shipped_is_idempotent(): void
    {
        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());
        $this->item->refresh();

        $this->fulfillAction()->markShipped($this->item, 'Speedy', 'TRACK123');
        $this->item->refresh();
        $this->fulfillAction()->markShipped($this->item, 'Speedy', 'TRACK123'); // second call

        $this->assertDatabaseHas('auction_items', ['fulfillment_status' => 'shipped']);
    }

    public function test_mark_delivered_is_idempotent(): void
    {
        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());
        $this->item->refresh();
        $this->fulfillAction()->markShipped($this->item, 'DHL', 'D999');
        $this->item->refresh();

        $this->fulfillAction()->markDelivered($this->item);
        $this->item->refresh();
        $this->fulfillAction()->markDelivered($this->item); // second call

        $this->assertDatabaseHas('auction_items', ['fulfillment_status' => 'delivered']);
    }

    // ── Guard errors ──────────────────────────────────────────────────────

    public function test_create_record_requires_paid_status(): void
    {
        $this->item->update(['fulfillment_status' => 'preparing']);

        $this->expectException(InvalidArgumentException::class);

        $this->fulfillAction()->createRecord($this->item, $this->winner->id, $this->address());
    }

    public function test_mark_shipped_requires_preparing_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fulfillAction()->markShipped($this->item, 'Speedy', 'T1'); // item is still 'paid'
    }

    public function test_mark_delivered_requires_shipped_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fulfillAction()->markDelivered($this->item); // item is still 'paid'
    }

    // ── Expired payment ───────────────────────────────────────────────────

    public function test_expire_marks_payment_failed(): void
    {
        $bid = Bid::create([
            'auction_item_id'           => $this->item->id,
            'user_id'                   => $this->winner->id,
            'amount_cents'              => 10000,
            'status'                    => 'won',
            'stripe_payment_intent_id'  => 'pi_expire_test',
        ]);

        $this->item->update([
            'winning_bid_id'     => $bid->id,
            'fulfillment_status' => 'awaiting_payment',
            'payment_deadline'   => now()->subHour(),
        ]);
        $this->item->refresh();

        $this->expireAction()->execute($this->item);

        $this->assertDatabaseHas('auction_items', [
            'id'                 => $this->item->id,
            'fulfillment_status' => 'payment_failed',
        ]);

        $this->assertDatabaseHas('bids', [
            'id'     => $bid->id,
            'status' => 'retracted',
        ]);
    }

    public function test_expire_is_noop_when_deadline_not_passed(): void
    {
        $this->item->update([
            'fulfillment_status' => 'awaiting_payment',
            'payment_deadline'   => now()->addHours(24),
        ]);
        $this->item->refresh();

        $this->expireAction()->execute($this->item);

        $this->assertDatabaseHas('auction_items', [
            'id'                 => $this->item->id,
            'fulfillment_status' => 'awaiting_payment',
        ]);
    }

    public function test_expire_is_noop_when_already_paid(): void
    {
        // item is in 'paid' status from setUp — not 'awaiting_payment'
        $this->expireAction()->execute($this->item);

        $this->assertDatabaseHas('auction_items', [
            'id'                 => $this->item->id,
            'fulfillment_status' => 'paid',
        ]);
    }

    public function test_expire_cancels_stripe_payment_intent(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->expects($this->once())->method('cancel')->with('pi_to_cancel');

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $bid = Bid::create([
            'auction_item_id'          => $this->item->id,
            'user_id'                  => $this->winner->id,
            'amount_cents'             => 10000,
            'status'                   => 'won',
            'stripe_payment_intent_id' => 'pi_to_cancel',
        ]);

        $this->item->update([
            'winning_bid_id'     => $bid->id,
            'fulfillment_status' => 'awaiting_payment',
            'payment_deadline'   => now()->subMinute(),
        ]);
        $this->item->refresh();

        (new ExpireAuctionWinnerPayment($stripe))->execute($this->item);
    }
}
