<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\AuctionItem;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use App\Notifications\OutbidNotification;
use App\Notifications\PaymentConfirmedNotification;
use App\Notifications\ShipmentDispatchedNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Phase 68: Deploy-readiness — health check, transactional email, notification idempotency.
 *
 * Three groups:
 *   A) Health check endpoint — shape, DB connectivity, public access
 *   B) Transactional email — critical notifications carry correct subject and content
 *   C) NotificationService — sendOnce is idempotent; mail failure never breaks a sale
 */
final class Phase68ApiTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────
    // A. Health check endpoint
    // ────────────────────────────────────────────────────────────────────────

    public function test_health_returns_200_when_db_is_up(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $response->assertJsonStructure([
            'status',
            'checks' => ['database', 'queue'],
            'version',
        ]);
        $this->assertSame('ok', $response->json('status'));
        $this->assertSame('ok', $response->json('checks.database'));
    }

    public function test_health_is_publicly_accessible_without_auth(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_health_check_survives_repeated_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/health')->assertOk();
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // B. Transactional email — notification content
    // ────────────────────────────────────────────────────────────────────────

    public function test_auction_won_notification_sent_to_winner(): void
    {
        NotificationFacade::fake();

        $winner  = User::factory()->create(['role' => 'buyer']);
        [$item]  = $this->makeClosedItem($winner->id);

        $winner->notify(new AuctionWonNotification($item, 25000, 'EUR'));

        NotificationFacade::assertSentTo($winner, AuctionWonNotification::class);
    }

    public function test_auction_won_notification_mail_has_correct_subject(): void
    {
        $winner = User::factory()->create(['role' => 'buyer']);
        [$item, $artwork] = $this->makeClosedItem($winner->id);

        $mail = (new AuctionWonNotification($item, 25000, 'EUR'))->toMail($winner);

        $this->assertStringContainsString($artwork->title, $mail->subject);
    }

    public function test_outbid_notification_mail_subject_contains_artwork_title(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        [$item, $artwork] = $this->makeClosedItem($buyer->id);

        $mail = (new OutbidNotification($item, 30000, 31000, 'EUR'))->toMail($buyer);

        $this->assertStringContainsString($artwork->title, $mail->subject);
    }

    public function test_payment_confirmed_notification_subject_contains_artwork(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $mail = (new PaymentConfirmedNotification(
            subject:   'Seascape at Dusk',
            amountCents: 25000,
            currency:  'EUR',
            reference: 'pi_test_001',
        ))->toMail($buyer);

        $this->assertStringContainsString('Seascape at Dusk', $mail->subject);
    }

    public function test_shipment_dispatched_notification_subject_contains_artwork_title(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $mail = (new ShipmentDispatchedNotification(
            artworkTitle:   'Abstract Blue',
            carrier:        'Speedy',
            trackingNumber: 'TRACK777',
        ))->toMail($buyer);

        $this->assertStringContainsString('Abstract Blue', $mail->subject);
    }

    // ────────────────────────────────────────────────────────────────────────
    // C. NotificationService — idempotency and fault isolation
    // ────────────────────────────────────────────────────────────────────────

    public function test_send_once_sends_and_records_as_sent(): void
    {
        NotificationFacade::fake();

        $user    = User::factory()->create();
        $service = new NotificationService();
        $key     = 'auction_won:item:99:buyer:' . $user->id;

        $sent = $service->sendOnce(
            $user,
            new PaymentConfirmedNotification('Test Work', 10000, 'EUR', 'pi_test'),
            $key,
        );

        $this->assertTrue($sent);

        $record = DB::table('notification_records')->where('idempotency_key', $key)->first();
        $this->assertNotNull($record);
        $this->assertSame('sent', $record->status);
    }

    public function test_send_once_is_idempotent_on_duplicate_key(): void
    {
        NotificationFacade::fake();

        $user    = User::factory()->create();
        $service = new NotificationService();
        $key     = 'dup_test:' . uniqid();

        $service->sendOnce($user, new PaymentConfirmedNotification('Art', 5000, 'EUR', 'pi_dup'), $key);
        $second = $service->sendOnce($user, new PaymentConfirmedNotification('Art', 5000, 'EUR', 'pi_dup'), $key);

        $this->assertFalse($second, 'Second call must be deduplicated');
        NotificationFacade::assertSentToTimes($user, PaymentConfirmedNotification::class, 1);
    }

    public function test_send_once_does_not_retry_failed_by_default(): void
    {
        NotificationFacade::fake();

        $user = User::factory()->create();
        $key  = 'failed_no_retry:' . uniqid();

        DB::table('notification_records')->insert([
            'idempotency_key' => $key,
            'user_id'         => $user->id,
            'type'            => PaymentConfirmedNotification::class,
            'channel'         => 'mail',
            'status'          => 'failed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $sent = (new NotificationService())->sendOnce(
            $user,
            new PaymentConfirmedNotification('Art', 5000, 'EUR', 'pi_no_retry'),
            $key,
            allowRetry: false,
        );

        $this->assertFalse($sent);
        NotificationFacade::assertNothingSent();
    }

    public function test_send_once_retries_failed_when_allow_retry_is_true(): void
    {
        NotificationFacade::fake();

        $user = User::factory()->create();
        $key  = 'failed_retry:' . uniqid();

        DB::table('notification_records')->insert([
            'idempotency_key' => $key,
            'user_id'         => $user->id,
            'type'            => PaymentConfirmedNotification::class,
            'channel'         => 'mail',
            'status'          => 'failed',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $sent = (new NotificationService())->sendOnce(
            $user,
            new PaymentConfirmedNotification('Art', 5000, 'EUR', 'pi_retry'),
            $key,
            allowRetry: true,
        );

        $this->assertTrue($sent);
        NotificationFacade::assertSentTo($user, PaymentConfirmedNotification::class);

        $record = DB::table('notification_records')->where('idempotency_key', $key)->first();
        $this->assertSame('sent', $record->status);
    }

    public function test_mail_failure_does_not_propagate_exception(): void
    {
        // A failed email must never abort a sale — exception must be swallowed.
        $user    = User::factory()->create();
        $service = new NotificationService();
        $key     = 'fail_isolate:' . uniqid();

        $sent = $service->sendOnce($user, new ThrowingNotification(), $key);

        $this->assertFalse($sent);

        $record = DB::table('notification_records')->where('idempotency_key', $key)->first();
        $this->assertNotNull($record);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('Mail server down', $record->error);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helper
    // ────────────────────────────────────────────────────────────────────────

    /** @return array{AuctionItem, Artwork} */
    private function makeClosedItem(int $userId): array
    {
        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Phase68 Painting ' . uniqid(),
            'slug'         => 'phase68-' . uniqid(),
            'status'       => 'sold',
            'medium'       => 'painting',
            'year_created' => 2024,
        ]);
        $lot = ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'sold',
            'currency'           => 'EUR',
            'starting_bid_cents' => 5000,
        ]);
        $auction = Auction::create([
            'title'     => 'Closed68 ' . uniqid(),
            'slug'      => 'closed-68-' . uniqid(),
            'status'    => 'closed',
            'currency'  => 'EUR',
            'starts_at' => now()->subDays(3),
            'ends_at'   => now()->subDay(),
        ]);
        $item = AuctionItem::create([
            'auction_id'         => $auction->id,
            'art_lot_id'         => $lot->id,
            'lot_number'         => 1,
            'status'             => 'sold',
            'fulfillment_status' => 'paid',
        ]);
        $bid = Bid::create([
            'auction_item_id' => $item->id,
            'user_id'         => $userId,
            'amount_cents'    => 25000,
            'status'          => 'won',
        ]);
        $item->update(['winning_bid_id' => $bid->id]);

        return [$item, $artwork];
    }
}

/**
 * Named test-only notification whose toMail always throws.
 * Anonymous classes produce class names that exceed notification_records.type (varchar 100).
 */
final class ThrowingNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        throw new \RuntimeException('Mail server down');
    }
}
