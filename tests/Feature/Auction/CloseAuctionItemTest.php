<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Domain\Auction\CloseAuctionItem;
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

class CloseAuctionItemTest extends TestCase
{
    use RefreshDatabase;

    private AuctionItem  $item;
    private StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $auction = Auction::create([
            'title'     => 'Auction',
            'slug'      => 'auction',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->subMinute(),
            'status'    => 'live',
            'currency'  => 'EUR',
        ]);

        $artwork = Artwork::create([
            'user_id' => User::factory()->create()->id,
            'title'   => 'Artwork',
            'slug'    => 'artwork',
            'status'  => 'in_auction',
        ]);

        $this->item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'artwork_id'          => $artwork->id,
            'lot_number'          => 1,
            'starting_bid_cents'  => 5000,
            'bid_increment_cents' => 500,
            'status'              => 'open',
        ]);

        // Stripe mock — cancel is a no-op by default
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')->willReturn(null);

        $this->stripe = $this->createMock(StripeClient::class);
        $this->stripe->method('__get')
            ->with('paymentIntents')
            ->willReturn($piService);
    }

    private function action(): CloseAuctionItem
    {
        return new CloseAuctionItem($this->stripe);
    }

    private function makeBid(int $cents, string $status = 'accepted', ?string $pi = null): Bid
    {
        return Bid::create([
            'auction_item_id'          => $this->item->id,
            'user_id'                  => User::factory()->create()->id,
            'amount_cents'             => $cents,
            'status'                   => $status,
            'stripe_payment_intent_id' => $pi,
        ]);
    }

    // ── Winner exists ─────────────────────────────────────────────

    public function test_marks_item_sold_and_bid_won(): void
    {
        $bid = $this->makeBid(5000);

        $this->action()->execute($this->item);

        $this->assertSame('sold', $this->item->fresh()->status);
        $this->assertSame($bid->id, $this->item->fresh()->winning_bid_id);
        $this->assertSame('won', $bid->fresh()->status);
    }

    public function test_picks_highest_accepted_bid_as_winner(): void
    {
        $low  = $this->makeBid(5000);
        $high = $this->makeBid(6000);

        $this->action()->execute($this->item);

        $this->assertSame($high->id, $this->item->fresh()->winning_bid_id);
        $this->assertSame('won',    $high->fresh()->status);
        $this->assertSame('accepted', $low->fresh()->status); // unchanged — already outbid before close
    }

    public function test_cancels_outbid_payment_intents(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->expects($this->once())
            ->method('cancel')
            ->with('pi_outbid');

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->makeBid(5000, 'outbid', 'pi_outbid');
        $winner = $this->makeBid(6000, 'accepted', 'pi_winner');

        (new CloseAuctionItem($stripe))->execute($this->item);

        $this->assertSame('won', $winner->fresh()->status);
    }

    // ── No bids ───────────────────────────────────────────────────

    public function test_marks_item_passed_when_no_accepted_bids(): void
    {
        $this->action()->execute($this->item);

        $this->assertSame('passed', $this->item->fresh()->status);
        $this->assertNull($this->item->fresh()->winning_bid_id);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_already_sold_item_is_a_noop(): void
    {
        $bid = $this->makeBid(5000);
        $this->item->update(['status' => 'sold', 'winning_bid_id' => $bid->id]);
        $bid->update(['status' => 'won']);

        $this->action()->execute($this->item);

        // Still sold, no double-processing
        $this->assertSame('sold', $this->item->fresh()->status);
        $this->assertSame($bid->id, $this->item->fresh()->winning_bid_id);
    }

    public function test_already_passed_item_is_a_noop(): void
    {
        $this->item->update(['status' => 'passed']);

        $this->action()->execute($this->item);

        $this->assertSame('passed', $this->item->fresh()->status);
    }

    public function test_canceled_item_is_a_noop(): void
    {
        $this->item->update(['status' => 'canceled']);

        $this->action()->execute($this->item);

        $this->assertSame('canceled', $this->item->fresh()->status);
    }

    // ── Stripe failure tolerance ──────────────────────────────────

    public function test_stripe_cancel_failure_does_not_prevent_close(): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('cancel')
            ->willThrowException(new \Stripe\Exception\InvalidRequestException('already canceled'));

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->makeBid(5000, 'outbid', 'pi_already_gone');
        $winner = $this->makeBid(6000, 'accepted');

        (new CloseAuctionItem($stripe))->execute($this->item);

        // Item still closes correctly despite Stripe error
        $this->assertSame('sold', $this->item->fresh()->status);
        $this->assertSame('won', $winner->fresh()->status);
    }
}
