<?php

declare(strict_types=1);

namespace Tests\Feature\Auction;

use App\Jobs\CloseAuctionItemJob;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloseExpiredLotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $this->venue  = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);
    }

    private function makeAuction(bool $ended): Auction
    {
        static $n = 0;
        $n++;
        return Auction::create([
            'title'     => "Auction {$n}",
            'slug'      => "auction-{$n}",
            'venue_id'  => $this->venue->id,
            'starts_at' => now()->subDays(2),
            'ends_at'   => $ended ? now()->subMinute() : now()->addHour(),
            'status'    => $ended ? 'closed' : 'live',
            'currency'  => 'EUR',
        ]);
    }

    private function makeItem(Auction $auction, string $status = 'open'): AuctionItem
    {
        static $lot = 0;
        $lot++;
        $artist  = User::factory()->create();
        $artwork = Artwork::create(['user_id' => $artist->id, 'title' => "Art{$lot}", 'slug' => "art-{$lot}", 'status' => 'in_auction']);

        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'artwork_id'          => $artwork->id,
            'lot_number'          => $lot,
            'starting_bid_cents'  => 5000,
            'bid_increment_cents' => 500,
            'status'              => $status,
        ]);
    }

    // ── Core dispatch logic ───────────────────────────────────────

    public function test_dispatches_job_for_each_expired_open_lot(): void
    {
        $endedAuction = $this->makeAuction(ended: true);
        $item1        = $this->makeItem($endedAuction);
        $item2        = $this->makeItem($endedAuction);

        $this->artisan('auction:close-expired-lots')->assertSuccessful();

        Queue::assertPushed(CloseAuctionItemJob::class, 2);
    }

    public function test_dispatches_correct_item_ids(): void
    {
        $endedAuction = $this->makeAuction(ended: true);
        $item         = $this->makeItem($endedAuction);

        $this->artisan('auction:close-expired-lots');

        Queue::assertPushed(CloseAuctionItemJob::class, function (CloseAuctionItemJob $job) use ($item): bool {
            return $job->auctionItemId === $item->id;
        });
    }

    public function test_dispatches_nothing_when_no_expired_lots(): void
    {
        $liveAuction = $this->makeAuction(ended: false);
        $this->makeItem($liveAuction);

        $this->artisan('auction:close-expired-lots')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── Status filters ────────────────────────────────────────────

    public function test_skips_already_sold_lots(): void
    {
        $endedAuction = $this->makeAuction(ended: true);
        $this->makeItem($endedAuction, 'sold');

        $this->artisan('auction:close-expired-lots');

        Queue::assertNothingPushed();
    }

    public function test_skips_passed_lots(): void
    {
        $endedAuction = $this->makeAuction(ended: true);
        $this->makeItem($endedAuction, 'passed');

        $this->artisan('auction:close-expired-lots');

        Queue::assertNothingPushed();
    }

    public function test_skips_canceled_lots(): void
    {
        $endedAuction = $this->makeAuction(ended: true);
        $this->makeItem($endedAuction, 'canceled');

        $this->artisan('auction:close-expired-lots');

        Queue::assertNothingPushed();
    }

    // ── Mixed state ───────────────────────────────────────────────

    public function test_only_processes_open_lots_in_ended_auctions(): void
    {
        $ended = $this->makeAuction(ended: true);
        $live  = $this->makeAuction(ended: false);

        $openExpired  = $this->makeItem($ended, 'open');   // ← should dispatch
        $soldExpired  = $this->makeItem($ended, 'sold');   // ← skip
        $openLive     = $this->makeItem($live,  'open');   // ← skip (not ended)

        $this->artisan('auction:close-expired-lots');

        Queue::assertPushed(CloseAuctionItemJob::class, 1);

        Queue::assertPushed(CloseAuctionItemJob::class, function (CloseAuctionItemJob $job) use ($openExpired): bool {
            return $job->auctionItemId === $openExpired->id;
        });
    }

    // ── Output ────────────────────────────────────────────────────

    public function test_outputs_dispatch_count(): void
    {
        $endedAuction = $this->makeAuction(ended: true);
        $this->makeItem($endedAuction);
        $this->makeItem($endedAuction);

        $this->artisan('auction:close-expired-lots')
             ->expectsOutput('Dispatched 2 close job(s).');
    }

    public function test_outputs_zero_when_nothing_to_process(): void
    {
        $this->artisan('auction:close-expired-lots')
             ->expectsOutput('Dispatched 0 close job(s).');
    }
}
