<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Auction\ExpireBidAuthorization;
use App\Domain\Donation\AssessDonorFiscalYear;
use App\Domain\Donation\DonationCalculator;
use App\Domain\Donation\EligibilityBasis;
use App\Models\Artwork;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Phase 57: Correctness and release-readiness fixes.
 *
 *   57-A  ZKPO annual ceiling calculation (DonationCalculator + AssessDonorFiscalYear)
 *   57-B  Bid authorization expiry (ExpireBidAuthorization + ExpireBidAuthorizations cmd)
 *   57-C  Notification retry path
 *   57-D  Evidence visibility enforcement in public artwork endpoint
 */
final class Phase57ApiTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // 57-A  ZKPO annual ceiling
    // ──────────────────────────────────────────────────────────────────────────

    public function test_donation_calculator_stores_full_donated_amount_as_provisional_max(): void
    {
        $calculator = new DonationCalculator();
        $result     = $calculator->calculate(100_000, EligibilityBasis::ZKPO_ART31_1);

        // Provisional max = donated amount, NOT donated × 10%
        $this->assertSame(100_000, $result->maxDeductibleCents);
        $this->assertSame(1000, $result->deductionBps); // 10%
    }

    public function test_assess_annual_ceiling_fully_within_profit(): void
    {
        // profit=1 000 000, donated=50 000, basis=10% → ceiling=100 000 → recognized=50 000
        $calculator = new DonationCalculator();
        $result     = $calculator->assessAnnualCeiling(
            positiveProfitCents: 1_000_000,
            totalDonatedCents:   50_000,
            basis:               EligibilityBasis::ZKPO_ART31_1,
        );

        $this->assertSame(100_000, $result->profitCeilingCents);
        $this->assertSame(50_000, $result->recognizedCents);
        $this->assertTrue($result->fullyRecognized());
        $this->assertSame(0, $result->excessCents());
    }

    public function test_assess_annual_ceiling_donation_exceeds_profit_ceiling(): void
    {
        // profit=100 000, donated=20 000, basis=10% → ceiling=10 000 → recognized=10 000
        $calculator = new DonationCalculator();
        $result     = $calculator->assessAnnualCeiling(
            positiveProfitCents: 100_000,
            totalDonatedCents:   20_000,
            basis:               EligibilityBasis::ZKPO_ART31_1,
        );

        $this->assertSame(10_000, $result->profitCeilingCents);
        $this->assertSame(10_000, $result->recognizedCents);
        $this->assertFalse($result->fullyRecognized());
        $this->assertSame(10_000, $result->excessCents()); // 20 000 - 10 000
    }

    public function test_assess_annual_ceiling_loss_year_zero_recognized(): void
    {
        // Negative profit → zero deductible regardless of donation amount
        $calculator = new DonationCalculator();
        $result     = $calculator->assessAnnualCeiling(
            positiveProfitCents: -500_000,
            totalDonatedCents:   10_000,
            basis:               EligibilityBasis::ZKPO_ART31_1,
        );

        $this->assertSame(0, $result->profitCeilingCents);
        $this->assertSame(0, $result->recognizedCents);
        $this->assertFalse($result->fullyRecognized());
    }

    public function test_assess_annual_ceiling_patronage_15_percent(): void
    {
        // profit=200 000, donated=25 000, basis=15% → ceiling=30 000 → recognized=25 000
        $calculator = new DonationCalculator();
        $result     = $calculator->assessAnnualCeiling(
            positiveProfitCents: 200_000,
            totalDonatedCents:   25_000,
            basis:               EligibilityBasis::ZKPO_ART31_3_PATRONAGE,
        );

        $this->assertSame(30_000, $result->profitCeilingCents);
        $this->assertSame(25_000, $result->recognizedCents);
        $this->assertTrue($result->fullyRecognized());
    }

    public function test_assess_donor_fiscal_year_persists_assessment(): void
    {
        $user = User::factory()->create();

        $fyId = DB::table('donor_fiscal_years')->insertGetId([
            'donor_id'               => $user->id,
            'fiscal_year'            => 2024,
            'eligibility_basis'      => 'ZKPO_ART31_1',
            'aggregate_donated_cents' => 80_000,
            'donation_count'         => 2,
            'assessment_status'      => 'pending',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $row    = \App\Models\DonorFiscalYear::find($fyId);
        $action = new AssessDonorFiscalYear();

        // profit=500 000, ceiling=50 000, donated=80 000 → recognized=50 000
        $result = $action->execute($row, 500_000);

        $this->assertSame(50_000, $result->profitCeilingCents);
        $this->assertSame(50_000, $result->recognizedCents);

        $fresh = DB::table('donor_fiscal_years')->where('id', $fyId)->first();
        $this->assertSame(500_000, (int) $fresh->positive_profit_cents);
        $this->assertSame(50_000, (int) $fresh->profit_ceiling_cents);
        $this->assertSame(50_000, (int) $fresh->recognized_deductible_cents);
        $this->assertSame('assessed', $fresh->assessment_status);
        $this->assertNotNull($fresh->assessed_at);
    }

    public function test_assess_donor_fiscal_year_throws_if_reviewed(): void
    {
        $user = User::factory()->create();

        $fyId = DB::table('donor_fiscal_years')->insertGetId([
            'donor_id'               => $user->id,
            'fiscal_year'            => 2023,
            'eligibility_basis'      => 'ZKPO_ART31_1',
            'aggregate_donated_cents' => 10_000,
            'donation_count'         => 1,
            'assessment_status'      => 'reviewed',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $row = \App\Models\DonorFiscalYear::find($fyId);

        $this->expectException(\LogicException::class);
        (new AssessDonorFiscalYear())->execute($row, 100_000);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 57-B  Bid authorization expiry
    // ──────────────────────────────────────────────────────────────────────────

    public function test_expire_bid_authorization_sets_payment_status(): void
    {
        // Stripe PI cancel will throw ApiErrorException (no real API key) — the action catches it.
        // We verify DB state only.
        $stripe = new StripeClient('sk_test_fake');
        $user   = User::factory()->create();
        $bidId  = $this->makeBidRow($user->id, 'accepted', 'authorized', 'pi_expire_test_' . uniqid());
        $bid    = Bid::find($bidId);

        $action = new ExpireBidAuthorization($stripe);
        $action->execute($bid);

        $this->assertSame('authorization_expired', $bid->fresh()->payment_status);
    }

    public function test_expire_bid_authorization_skips_captured(): void
    {
        $stripe = new StripeClient('sk_test_fake');
        $user   = User::factory()->create();
        $bidId  = $this->makeBidRow($user->id, 'won', 'captured', 'pi_captured_test_' . uniqid());
        $bid    = Bid::find($bidId);

        $action = new ExpireBidAuthorization($stripe);
        $action->execute($bid);

        // payment_status must remain 'captured' — early return before any Stripe call
        $this->assertSame('captured', $bid->fresh()->payment_status);
    }

    public function test_expire_bid_authorizations_command_processes_expired_bids(): void
    {
        // Bind a fake Stripe client — Stripe cancel will throw (caught by the action).
        $this->app->instance(StripeClient::class, new StripeClient('sk_test_fake'));

        $user = User::factory()->create();

        // Expired bid (authorization_expires_at in the past)
        $expiredId = $this->makeBidRow($user->id, 'accepted', 'authorized', 'pi_exp_cmd_' . uniqid(), now()->subDay());

        // Not yet expired
        $freshId = $this->makeBidRow($user->id, 'accepted', 'authorized', 'pi_fresh_cmd_' . uniqid(), now()->addDays(5));

        $this->artisan('auction:expire-bid-authorizations')->assertSuccessful();

        $this->assertSame('authorization_expired', Bid::find($expiredId)->payment_status);
        $this->assertSame('authorized', Bid::find($freshId)->payment_status);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 57-C  Notification retry
    // ──────────────────────────────────────────────────────────────────────────

    public function test_notification_send_once_deduplicates_sent(): void
    {
        $user    = User::factory()->create();
        $service = app(\App\Services\NotificationService::class);
        $key     = 'phase57-test-sent-' . uniqid();

        DB::table('notification_records')->insert([
            'idempotency_key' => $key,
            'user_id'         => $user->id,
            'type'            => 'TestNotif',
            'channel'         => 'mail',
            'status'          => 'sent',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $notif  = $this->makeTestNotification();
        $result = $service->sendOnce($user, $notif, $key);

        $this->assertFalse($result, 'sendOnce must return false for already-sent key');
    }

    public function test_notification_retry_resets_failed_record(): void
    {
        $user    = User::factory()->create();
        $service = app(\App\Services\NotificationService::class);
        $key     = 'phase57-test-retry-' . uniqid();

        DB::table('notification_records')->insert([
            'idempotency_key' => $key,
            'user_id'         => $user->id,
            'type'            => 'TestNotif',
            'channel'         => 'mail',
            'status'          => 'failed',
            'error'           => 'SMTP timeout',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Without allowRetry — still blocked
        $notif  = $this->makeTestNotification();
        $result = $service->sendOnce($user, $notif, $key, allowRetry: false);
        $this->assertFalse($result, 'sendOnce must return false for failed key without allowRetry');

        // Record is still 'failed' — allowRetry resets it to queued and retries.
        // notify() may or may not throw depending on mail driver; we check DB state only.
        $service->sendOnce($user, $notif, $key, allowRetry: true);

        // The record must no longer be 'failed' — it was reset before the attempt.
        $record = DB::table('notification_records')->where('idempotency_key', $key)->first();
        $this->assertNotNull($record);
        $this->assertNotSame('failed', $record->status, 'Record must not stay "failed" after allowRetry reset');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 57-D  Evidence visibility enforcement
    // ──────────────────────────────────────────────────────────────────────────

    public function test_public_endpoint_returns_only_public_visibility_evidence(): void
    {
        $artist  = User::factory()->create();
        DB::table('users')->where('id', $artist->id)->update(['role' => 'artist']);

        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Visibility Test Artwork',
            'slug'         => 'visibility-test-' . uniqid(),
            'status'       => 'listed',
            'currency'     => 'BGN',
            'year_created' => 2024,
            'medium'       => 'painting',
        ]);

        // Three evidence rows with different visibility
        DB::table('artwork_evidence')->insert([
            [
                'artwork_id'          => $artwork->id,
                'type'                => 'certificate',
                'issuer'              => 'Gallery A',
                'issued_at'           => '2024-01-01',
                'verification_status' => 'verified',
                'visibility'          => 'public',
                'created_at'          => now(),
                'updated_at'          => now(),
            ],
            [
                'artwork_id'          => $artwork->id,
                'type'                => 'certificate',
                'issuer'              => 'Gallery B',
                'issued_at'           => '2024-02-01',
                'verification_status' => 'verified',
                'visibility'          => 'restricted',
                'created_at'          => now(),
                'updated_at'          => now(),
            ],
            [
                'artwork_id'          => $artwork->id,
                'type'                => 'certificate',
                'issuer'              => 'Gallery C',
                'issued_at'           => '2024-03-01',
                'verification_status' => 'verified',
                'visibility'          => 'sensitive',
                'created_at'          => now(),
                'updated_at'          => now(),
            ],
        ]);

        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/evidence");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data, 'Public endpoint must return only visibility=public evidence');
        $this->assertSame('Gallery A', $data[0]['issuer']);
    }

    public function test_admin_sees_all_evidence_visibility_levels(): void
    {
        $admin   = User::factory()->create();
        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin']);
        $admin = $admin->fresh();

        $artist  = User::factory()->create();
        $artwork = Artwork::create([
            'user_id'      => $artist->id,
            'title'        => 'Admin Evidence Test',
            'slug'         => 'admin-evidence-' . uniqid(),
            'status'       => 'listed',
            'currency'     => 'BGN',
            'year_created' => 2024,
            'medium'       => 'painting',
        ]);

        foreach (['public', 'restricted', 'sensitive'] as $v) {
            DB::table('artwork_evidence')->insert([
                'artwork_id'          => $artwork->id,
                'type'                => 'certificate',
                'issuer'              => "Issuer-{$v}",
                'issued_at'           => '2024-01-01',
                'verification_status' => 'verified',
                'visibility'          => $v,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/artworks/{$artwork->slug}/evidence");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'), 'Admin must see all visibility levels');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeTestNotification(): \Illuminate\Notifications\Notification
    {
        return new class extends \Illuminate\Notifications\Notification {
            public function via($notifiable): array { return ['mail']; }
            public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage
            {
                return (new \Illuminate\Notifications\Messages\MailMessage)->line('Test');
            }
        };
    }

    /**
     * Insert a minimal auction → auction_item → bid chain and return the bid id.
     */
    private function makeBidRow(
        int $userId,
        string $status,
        string $paymentStatus,
        string $piId,
        ?\DateTimeInterface $expiresAt = null,
    ): int {
        $artworkId = DB::table('artworks')->insertGetId([
            'user_id'      => $userId,
            'title'        => 'Bid Test Artwork ' . uniqid(),
            'slug'         => 'bid-test-' . uniqid(),
            'status'       => 'in_auction',
            'year_created' => 2023,
            'medium'       => 'painting',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $artLotId = DB::table('art_lots')->insertGetId([
            'artwork_id'         => $artworkId,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 5_000,
            'currency'           => 'EUR',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $auctionId = DB::table('auctions')->insertGetId([
            'title'      => 'Test Auction ' . uniqid(),
            'slug'       => 'test-auction-' . uniqid(),
            'status'     => 'live',
            'currency'   => 'EUR',
            'starts_at'  => now()->subHour(),
            'ends_at'    => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('auction_items')->insertGetId([
            'auction_id'  => $auctionId,
            'art_lot_id'  => $artLotId,
            'lot_number'  => rand(1, 9999),
            'status'      => 'sold',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return DB::table('bids')->insertGetId([
            'auction_item_id'        => $itemId,
            'user_id'                => $userId,
            'amount_cents'           => 10_000,
            'status'                 => $status,
            'payment_status'         => $paymentStatus,
            'stripe_payment_intent_id' => $piId,
            'authorization_expires_at' => $expiresAt ?? now()->subDay(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }
}
