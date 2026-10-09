<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuctionItem;
use App\Models\Dispute;
use App\Models\Donation;
use App\Models\User;
use App\Notifications\AuctionWonNotification;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DonationRecordedNotification;
use App\Notifications\PaymentConfirmedNotification;
use App\Notifications\ShipmentDispatchedNotification;
use App\Notifications\WinnerPaymentRequiredNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 51: Transactional email notifications — idempotency service and
 * mail content contracts for all high-priority events.
 */
final class Phase51ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Idempotency service ───────────────────────────────────────────────────

    public function test_send_once_dispatches_notification_first_call(): void
    {
        Notification::fake();

        $user    = User::factory()->create();
        $service = app(NotificationService::class);
        $notif   = new PaymentConfirmedNotification(
            subject: 'Test Purchase',
            amountCents: 5000,
            currency: 'BGN',
            reference: 'pi_test123',
        );

        $result = $service->sendOnce($user, $notif, 'payment_confirmed.pi_test123.' . $user->id);

        $this->assertTrue($result);
        Notification::assertSentTo($user, PaymentConfirmedNotification::class);
        $this->assertDatabaseHas('notification_records', [
            'idempotency_key' => 'payment_confirmed.pi_test123.' . $user->id,
            'status'          => 'sent',
        ]);
    }

    public function test_send_once_skips_duplicate_key(): void
    {
        Notification::fake();

        $user    = User::factory()->create();
        $service = app(NotificationService::class);
        $key     = 'payment_confirmed.pi_test456.' . $user->id;
        $notif   = new PaymentConfirmedNotification('Test', 5000, 'BGN', 'pi_test456');

        $service->sendOnce($user, $notif, $key);
        $result2 = $service->sendOnce($user, $notif, $key);

        $this->assertFalse($result2);
        Notification::assertSentToTimes($user, PaymentConfirmedNotification::class, 1);
        $this->assertSame(1, DB::table('notification_records')->where('idempotency_key', $key)->count());
    }

    // ── Mail content contracts ────────────────────────────────────────────────

    private function makeAuctionItem(): AuctionItem
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $artwork = \App\Models\Artwork::create([
            'user_id'       => $owner->id,
            'title'         => 'Test Artwork',
            'slug'          => 'test-artwork-' . uniqid(),
            'status'        => 'listed',
            'currency'      => 'BGN',
            'year_created'  => 2020,
            'medium'        => 'painting',
        ]);
        $lot = \App\Models\ArtLot::create([
            'artwork_id'         => $artwork->id,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 10000,
            'currency'           => 'BGN',
        ]);
        $auction = \App\Models\Auction::create([
            'title'    => 'Test Auction',
            'slug'     => 'test-auction-' . uniqid(),
            'status'   => 'live',
            'currency' => 'BGN',
            'starts_at' => now()->subHour(),
            'ends_at'   => now()->addHour(),
        ]);
        return AuctionItem::create([
            'auction_id'          => $auction->id,
            'art_lot_id'          => $lot->id,
            'lot_number'          => 1,
            'bid_increment_cents' => 500,
            'status'              => 'sold',
        ]);
    }

    public function test_auction_won_notification_mail_content(): void
    {
        $user = User::factory()->create(['name' => 'Art Buyer']);
        $item = $this->makeAuctionItem();

        $notif   = new AuctionWonNotification($item, 15000, 'BGN');
        $message = $notif->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $message);
        $this->assertStringContainsString('won the auction', $message->subject);
        $this->assertSame(['mail'], $notif->via($user));
    }

    public function test_winner_payment_required_notification_mail_content(): void
    {
        $user = User::factory()->create(['name' => 'Winner']);
        $item = $this->makeAuctionItem();

        $notif   = new WinnerPaymentRequiredNotification($item, 15000, 'BGN', now()->addDays(3));
        $message = $notif->toMail($user);

        $this->assertStringContainsString('Payment required', $message->subject);
        $this->assertSame(['mail'], $notif->via($user));
    }

    public function test_payment_confirmed_notification_mail_content(): void
    {
        $user = User::factory()->create();

        $notif   = new PaymentConfirmedNotification('Test Book', 2500, 'BGN', 'pi_abc');
        $message = $notif->toMail($user);

        $this->assertStringContainsString('Payment confirmed', $message->subject);
        $this->assertStringContainsString('Test Book', $message->subject);
    }

    public function test_shipment_dispatched_notification_mail_content(): void
    {
        $user  = User::factory()->create();
        $notif = new ShipmentDispatchedNotification(
            artworkTitle: 'Blue Canvas',
            carrier: 'DHL',
            trackingNumber: 'DHL123456789',
            trackingUrl: 'https://track.dhl.com/DHL123456789',
        );

        $message = $notif->toMail($user);
        $this->assertStringContainsString('dispatched', $message->subject);
        $this->assertStringContainsString('Blue Canvas', $message->subject);
    }

    public function test_donation_recorded_notification_mail_content(): void
    {
        $user      = User::factory()->create();
        $recipientId = DB::table('donation_recipients')->insertGetId([
            'name'              => 'Red Cross BG',
            'eik'               => '123456789',
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => 'active',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $donationId = DB::table('donations')->insertGetId([
            'donor_id'              => $user->id,
            'donation_recipient_id' => $recipientId,
            'donated_cents'         => 10000,
            'currency'              => 'BGN',
            'eligibility_basis'     => 'ZKPO_ART31_1',
            'deduction_bps'         => 1000,
            'max_deductible_cents'  => 1000,
            'type'                  => 'donation',
            'status'                => 'confirmed',
            'idempotency_key'       => 'test-donation-' . uniqid(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $donation = Donation::find($donationId);
        $notif    = new DonationRecordedNotification($donation);
        $message  = $notif->toMail($user);

        $this->assertStringContainsString('Donation confirmed', $message->subject);
        $this->assertSame(['mail'], $notif->via($user));
    }

    public function test_dispute_opened_notification_mail_content(): void
    {
        $user    = User::factory()->create(['role' => 'buyer']);
        $dispute = Dispute::create([
            'opened_by'   => $user->id,
            'type'        => 'condition_mismatch',
            'description' => 'Item not as described',
            'status'      => 'open',
        ]);

        $notif   = new DisputeOpenedNotification($dispute);
        $message = $notif->toMail($user);

        $this->assertStringContainsString('Dispute opened', $message->subject);
        $this->assertStringContainsString((string) $dispute->id, $message->subject);
    }
}
