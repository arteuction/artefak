<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\SettleAuction;
use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\SettlementCalculator;
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
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

class SettleAuctionTest extends TestCase
{
    use RefreshDatabase;

    private Auction      $auction;
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $this->auction = Auction::create([
            'title'     => 'Test Auction',
            'slug'      => 'test-auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->subMinute(),
            'status'    => 'closed',
            'currency'  => 'EUR',
        ]);

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')->willReturn(null);

        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')
            ->with('paymentIntents')
            ->willReturn($piService);
    }

    private function action(): SettleAuction
    {
        return new SettleAuction(
            $this->stripe,
            new SettlementCalculator(),
            new CreateSettlement(),
        );
    }

    private function createSoldItem(string $piId, int $amountCents = 10000): AuctionItem
    {
        static $lot = 0;
        $lot++;

        $artist  = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Artwork ' . $piId,
            'slug'    => 'artwork-' . $piId,
            'status'  => 'sold',
        ]);

        $item = AuctionItem::create([
            'auction_id'          => $this->auction->id,
            'artwork_id'          => $artwork->id,
            'lot_number'          => $lot,
            'starting_bid_cents'  => 5000,
            'bid_increment_cents' => 500,
            'status'              => 'sold',
        ]);

        $bid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => User::factory()->create()->id,
            'amount_cents'             => $amountCents,
            'status'                   => 'won',
            'stripe_payment_intent_id' => $piId,
        ]);

        $item->update(['winning_bid_id' => $bid->id]);

        return $item->fresh();
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_captures_payment_intent_for_each_sold_item(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->expects($this->exactly(2))
            ->method('capture')
            ->willReturn(null);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->createSoldItem('pi_item_one');
        $this->createSoldItem('pi_item_two');

        (new SettleAuction($stripe, new SettlementCalculator(), new CreateSettlement()))
            ->execute($this->auction, 'evt_test_123');
    }

    public function test_creates_settlement_row_per_sold_item(): void
    {
        $this->createSoldItem('pi_settle_one', 10000);
        $this->createSoldItem('pi_settle_two', 20000);

        $ids = $this->action()->execute($this->auction, 'evt_test_456');

        $this->assertCount(2, $ids);
        $this->assertDatabaseCount('settlements', 2);
    }

    public function test_creates_three_settlement_lines_per_item(): void
    {
        $this->createSoldItem('pi_lines_test', 10000);

        $this->action()->execute($this->auction, 'evt_test_789');

        // artist + fund + ops = 3 lines
        $this->assertDatabaseCount('settlement_lines', 3);
    }

    public function test_settlement_lines_have_correct_45_45_10_split(): void
    {
        $this->createSoldItem('pi_split_test', 10000);

        $this->action()->execute($this->auction, 'evt_split');

        $this->assertDatabaseHas('settlements', [
            'stripe_payment_intent_id' => 'pi_split_test',
            'gross_cents'              => 10000,
            'artist_cents'             => 4500,
            'fund_cents'               => 4500,
            'ops_cents'                => 1000,
        ]);
    }

    public function test_creates_ledger_entry_per_settlement_line(): void
    {
        $this->createSoldItem('pi_ledger_test', 10000);

        $this->action()->execute($this->auction, 'evt_ledger');

        // 3 lines = 3 ledger entries
        $this->assertDatabaseCount('ledger_entries', 3);
    }

    public function test_returns_empty_array_when_no_sold_items(): void
    {
        $ids = $this->action()->execute($this->auction, 'evt_empty');

        $this->assertSame([], $ids);
        $this->assertDatabaseCount('settlements', 0);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_idempotent_on_duplicate_stripe_event(): void
    {
        $this->createSoldItem('pi_idempotent', 10000);

        $ids1 = $this->action()->execute($this->auction, 'evt_dup');
        $ids2 = $this->action()->execute($this->auction, 'evt_dup');

        $this->assertSame($ids1[0], $ids2[0]);
        $this->assertDatabaseCount('settlements', 1);
    }

    // ── Stripe failure tolerance ──────────────────────────────────

    public function test_skips_item_when_capture_fails(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')
            ->willThrowException(new \Stripe\Exception\InvalidRequestException('already captured'));

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->createSoldItem('pi_already_captured', 10000);

        $ids = (new SettleAuction($stripe, new SettlementCalculator(), new CreateSettlement()))
            ->execute($this->auction, 'evt_capture_fail');

        // Skipped, not crashed
        $this->assertSame([], $ids);
        $this->assertDatabaseCount('settlements', 0);
    }

    // ── Skips ineligible items ────────────────────────────────────

    public function test_skips_open_and_passed_items(): void
    {
        $artist  = User::factory()->create();
        $artwork = Artwork::create(['user_id' => $artist->id, 'title' => 'Open', 'slug' => 'open', 'status' => 'in_auction']);

        AuctionItem::create([
            'auction_id'          => $this->auction->id,
            'artwork_id'          => $artwork->id,
            'lot_number'          => 99,
            'starting_bid_cents'  => 5000,
            'bid_increment_cents' => 500,
            'status'              => 'open', // not sold
        ]);

        $ids = $this->action()->execute($this->auction, 'evt_skip');

        $this->assertSame([], $ids);
    }
}
