<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Console\Commands\ExpireBidAuthorizations;
use App\Domain\Auction\ExpireBidAuthorization;
use App\Domain\Auction\ReAuthorizeBid;
use App\Jobs\HandleStripeWebhook;
use App\Models\ArtLot;
use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\User;
use App\Events\AdminOperationalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Phase 69: Webhook routing + authorization expiry + re-authorization.
 *
 * Sections:
 *   A) HandleStripeWebhook job — payment_intent event routing + lifecycle
 *   B) ExpireBidAuthorizations command — marks expired PIs, fires operational alerts
 *   C) Re-authorization — winner re-authorizes expired payment via API
 */
final class Phase69ApiTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // A. HandleStripeWebhook job
    // ────────────────────────────────────────────────────────────────────────

    public function test_payment_intent_succeeded_marks_settlement_completed(): void
    {
        $piId       = 'pi_succeeded_001';
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $piId,
            'stripe_event_id'          => 'evt_old',
            'status'                   => 'pending',
            'gross_cents'              => 10000,
            'currency'                 => 'EUR',
            'profile_key'              => 'standard',
            'profile_version'          => 1,
            'artist_bps'               => 8500,
            'fund_bps'                 => 1000,
            'ops_bps'                  => 500,
            'artist_cents'             => 8500,
            'fund_cents'               => 1000,
            'ops_cents'                => 500,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $eventId = $this->insertWebhookEvent([
            'id'   => 'evt_pi_succeeded',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $piId, 'amount_received' => 10000, 'currency' => 'eur']],
        ]);

        $this->app->call([(new HandleStripeWebhook($eventId)), 'handle']);

        $this->assertSame(
            'completed',
            DB::table('settlements')->where('id', $settlementId)->value('status'),
        );
        $this->assertSame(
            'processed',
            DB::table('webhook_events')->where('id', $eventId)->value('status'),
        );
    }

    public function test_payment_intent_amount_capturable_updated_settles_closed_auction(): void
    {
        $piId  = 'pi_capturable_001';
        $buyer = User::factory()->create(['role' => 'buyer']);
        [$auction, $item, $bid] = $this->makeSoldScenario($buyer->id, $piId, auctionStatus: 'closed');

        // Bind mocked StripeClient so SettleAuction captures the PI without hitting real Stripe
        $captured = [];
        $settleStripe = $this->makeCapturingStripe($captured);
        $this->app->instance(StripeClient::class, $settleStripe);

        $eventId = $this->insertWebhookEvent([
            'id'   => 'evt_capturable_001',
            'type' => 'payment_intent.amount_capturable_updated',
            'data' => ['object' => ['id' => $piId]],
        ]);

        $this->app->call([(new HandleStripeWebhook($eventId)), 'handle']);

        $this->assertSame(
            'processed',
            DB::table('webhook_events')->where('id', $eventId)->value('status'),
        );
        $this->assertGreaterThan(
            0,
            DB::table('settlements')->where('stripe_payment_intent_id', $piId)->count(),
        );
    }

    public function test_capturable_updated_does_not_settle_live_auction(): void
    {
        $piId  = 'pi_capturable_live';
        $buyer = User::factory()->create(['role' => 'buyer']);
        $this->makeSoldScenario($buyer->id, $piId, auctionStatus: 'live');

        $eventId = $this->insertWebhookEvent([
            'id'   => 'evt_capturable_live',
            'type' => 'payment_intent.amount_capturable_updated',
            'data' => ['object' => ['id' => $piId]],
        ]);

        $this->app->call([(new HandleStripeWebhook($eventId)), 'handle']);

        $this->assertSame(
            0,
            DB::table('settlements')->where('stripe_payment_intent_id', $piId)->count(),
            'Live auction must not be settled via capturable_updated webhook',
        );
    }

    public function test_payment_intent_failed_is_handled_gracefully(): void
    {
        $piId = 'pi_failed_001';
        DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => $piId,
            'stripe_event_id'          => 'evt_old_failed',
            'status'                   => 'pending',
            'gross_cents'              => 10000,
            'currency'                 => 'EUR',
            'profile_key'              => 'standard',
            'profile_version'          => 1,
            'artist_bps'               => 8500,
            'fund_bps'                 => 1000,
            'ops_bps'                  => 500,
            'artist_cents'             => 8500,
            'fund_cents'               => 1000,
            'ops_cents'                => 500,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $eventId = $this->insertWebhookEvent([
            'id'   => 'evt_pi_failed',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => $piId]],
        ]);

        $this->app->call([(new HandleStripeWebhook($eventId)), 'handle']);

        $this->assertSame(
            'processed',
            DB::table('webhook_events')->where('id', $eventId)->value('status'),
        );
    }

    public function test_webhook_job_unknown_event_type_completes_without_error(): void
    {
        $eventId = DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_err_' . uniqid(),
            'type'            => 'unknown.event.type',
            'payload'         => json_encode([
                'id'   => 'evt_err_001',
                'type' => 'unknown.event.type',
                'data' => ['object' => []],
            ]),
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->app->call([(new HandleStripeWebhook($eventId)), 'handle']);

        $this->assertSame(
            'processed',
            DB::table('webhook_events')->where('id', $eventId)->value('status'),
        );
    }

    public function test_already_processed_event_is_skipped(): void
    {
        $eventId = DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => 'evt_already_done',
            'type'            => 'payment_intent.succeeded',
            'payload'         => json_encode([
                'id'   => 'evt_already_done',
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => ['id' => 'pi_noop', 'amount_received' => 1000, 'currency' => 'eur']],
            ]),
            'status'          => 'processed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->app->call([(new HandleStripeWebhook($eventId)), 'handle']);

        $this->assertSame(
            0,
            DB::table('settlements')->where('stripe_payment_intent_id', 'pi_noop')->count(),
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // B. ExpireBidAuthorizations command
    // ────────────────────────────────────────────────────────────────────────

    public function test_expire_command_marks_authorized_bids_as_expired(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        [, $item,] = $this->makeOpenScenario();

        $bid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $buyer->id,
            'amount_cents'             => 15000,
            'status'                   => 'accepted',
            'payment_status'           => 'authorized',
            'stripe_payment_intent_id' => 'pi_expires_001',
            'authorization_expires_at' => now()->subHour(), // already expired
        ]);

        $this->artisan('auction:expire-bid-authorizations')->assertSuccessful();

        $bid->refresh();
        $this->assertSame('authorization_expired', $bid->payment_status);
    }

    public function test_expire_command_fires_alert_for_winning_bid(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $buyer = User::factory()->create(['role' => 'buyer']);
        [, $item,] = $this->makeOpenScenario();

        $bid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $buyer->id,
            'amount_cents'             => 20000,
            'status'                   => 'won',
            'payment_status'           => 'authorized',
            'stripe_payment_intent_id' => 'pi_won_expires',
            'authorization_expires_at' => now()->subMinutes(10),
        ]);
        $item->update(['winning_bid_id' => $bid->id]);

        $this->artisan('auction:expire-bid-authorizations')->assertSuccessful();

        Event::assertDispatched(AdminOperationalAlert::class, function (AdminOperationalAlert $event) {
            return $event->alertType === 'winning_bid_authorization_expired';
        });
    }

    public function test_expire_command_does_not_touch_captured_bids(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        [, $item,] = $this->makeOpenScenario();

        $bid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $buyer->id,
            'amount_cents'             => 20000,
            'status'                   => 'won',
            'payment_status'           => 'captured',
            'stripe_payment_intent_id' => 'pi_captured_skip',
            'authorization_expires_at' => now()->subHour(),
        ]);

        $this->artisan('auction:expire-bid-authorizations')->assertSuccessful();

        $bid->refresh();
        $this->assertSame('captured', $bid->payment_status, 'Captured bids must not be expired');
    }

    // ────────────────────────────────────────────────────────────────────────
    // C. Re-authorization — winner re-authorizes expired payment
    // ────────────────────────────────────────────────────────────────────────

    public function test_winner_can_reauthorize_expired_bid(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        [$auction, $item, $bid] = $this->makeSoldScenario($buyer->id, 'pi_old_expired', 'closed');
        $bid->update(['payment_status' => 'authorization_expired']);

        $this->bindReAuthStripe('pi_new_reauth_001');

        $response = $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/auction-items/{$item->id}/re-authorize", [
                'payment_method_id' => 'pm_card_visa',
            ])
            ->assertOk();

        $response->assertJsonStructure(['bid' => ['id', 'payment_status', 'authorization_expires_at']]);
        $this->assertSame('authorized', $response->json('bid.payment_status'));
    }

    public function test_non_winner_cannot_reauthorize(): void
    {
        $winner = User::factory()->create(['role' => 'buyer']);
        $other  = User::factory()->create(['role' => 'buyer']);
        [, $item, $bid] = $this->makeSoldScenario($winner->id, 'pi_other_exp', 'closed');
        $bid->update(['payment_status' => 'authorization_expired']);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/auction-items/{$item->id}/re-authorize", [
                'payment_method_id' => 'pm_card_visa',
            ])
            ->assertNotFound();
    }

    public function test_already_captured_bid_cannot_be_reauthorized(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        [, $item, $bid] = $this->makeSoldScenario($buyer->id, 'pi_captured_noreauth', 'closed');
        $bid->update(['payment_status' => 'captured']);

        $this->bindReAuthStripe('pi_should_not_be_created');

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/auction-items/{$item->id}/re-authorize", [
                'payment_method_id' => 'pm_card_visa',
            ])
            ->assertUnprocessable();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function insertWebhookEvent(array $event): int
    {
        return DB::table('webhook_events')->insertGetId([
            'stripe_event_id' => $event['id'],
            'type'            => $event['type'],
            'payload'         => json_encode($event),
            'status'          => 'received',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /** @return array{Auction, AuctionItem, Bid} */
    private function makeSoldScenario(int $userId, string $piId, string $auctionStatus): array
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Phase69 Art ' . uniqid(),
            'slug'         => 'phase69-' . uniqid(),
            'status'       => 'sold',
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);
        $lot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'consignor_id'       => $artist->id,
            'sale_mode'          => 'auction',
            'status'             => 'sold',
            'currency'           => 'EUR',
            'starting_bid_cents' => 10000,
            'split_profile_key'  => 'social_pilot_45_45_10',
        ]);
        $auction = Auction::create([
            'title'     => 'Phase69 Auction ' . uniqid(),
            'slug'      => 'phase69-auction-' . uniqid(),
            'status'    => $auctionStatus,
            'currency'  => 'EUR',
            'starts_at' => now()->subDays(2),
            'ends_at'   => now()->subDay(),
        ]);
        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => 'sold',
            'fulfillment_status'  => 'awaiting_payment',
            'payment_deadline'    => now()->addHours(48),
        ]);
        $bid = Bid::create([
            'auction_item_id'          => $item->id,
            'user_id'                  => $userId,
            'amount_cents'             => 15000,
            'status'                   => 'won',
            'payment_status'           => 'authorized',
            'stripe_payment_intent_id' => $piId,
            'authorization_expires_at' => now()->addDays(7),
        ]);
        $item->update(['winning_bid_id' => $bid->id]);

        return [$auction, $item, $bid];
    }

    /** @return array{Auction, AuctionItem} */
    private function makeOpenScenario(): array
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Open Art ' . uniqid(),
            'slug'         => 'open-art-' . uniqid(),
            'status'       => 'in_auction',
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);
        $lot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'currency'           => 'EUR',
            'starting_bid_cents' => 5000,
        ]);
        $auction = Auction::create([
            'title'     => 'Open Auction ' . uniqid(),
            'slug'      => 'open-auction-' . uniqid(),
            'status'    => 'live',
            'currency'  => 'EUR',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
        ]);
        $item = AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 1000,
            'status'              => 'open',
        ]);

        return [$auction, $item];
    }

    private function makeCapturingStripe(array &$captured): StripeClient
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')->willReturnCallback(function (string $piId) use (&$captured) {
            $captured[] = $piId;
            return null;
        });

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        return $stripe;
    }

    private function bindReAuthStripe(string $newPiId): void
    {
        $pi = $this->createMock(PaymentIntent::class);
        $pi->method('__get')->with('id')->willReturn($newPiId);

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('create')->willReturn($pi);

        $stripe = $this->createMock(StripeClient::class);
        $stripe->method('__get')->with('paymentIntents')->willReturn($piService);

        $this->app->instance(StripeClient::class, $stripe);
    }
}
