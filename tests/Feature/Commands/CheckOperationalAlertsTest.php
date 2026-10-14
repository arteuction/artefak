<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Events\AdminOperationalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for arteuction:check-operational-alerts
 *
 * Covers the four new checks added in the queue-backpressure branch:
 *   - failed_jobs table alerts
 *   - stale reserved jobs (worker hung/dead)
 *   - abandoned transfer_outbox leases
 *   - abandoned transfer_reversal_order leases
 *
 * Existing checks (transfer_failed, domain_event_stuck, domain_event_failed)
 * are exercised implicitly; explicit coverage for new checks only here.
 */
final class CheckOperationalAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function runCommand(): void
    {
        $this->artisan('arteuction:check-operational-alerts')->assertSuccessful();
    }

    private function firedAlertTypes(): array
    {
        $events = Event::dispatched(AdminOperationalAlert::class);
        return collect($events)->map(fn ($args) => $args[0]->alertType)->toArray();
    }

    // ── Clean state: no alerts ────────────────────────────────────────────────

    public function test_no_alerts_when_all_tables_are_clean(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $this->runCommand();

        Event::assertNotDispatched(AdminOperationalAlert::class);
    }

    // ── Failed queue jobs ─────────────────────────────────────────────────────

    public function test_failed_queue_jobs_fires_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        DB::table('failed_jobs')->insert([
            'uuid'       => 'uuid-1',
            'connection' => 'database',
            'queue'      => 'critical',
            'payload'    => '{}',
            'exception'  => 'RuntimeException',
            'failed_at'  => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid'       => 'uuid-2',
            'connection' => 'database',
            'queue'      => 'critical',
            'payload'    => '{}',
            'exception'  => 'RuntimeException',
            'failed_at'  => now(),
        ]);

        $this->runCommand();

        Event::assertDispatched(AdminOperationalAlert::class, function ($e) {
            return $e->alertType === 'queue_jobs_failed' && $e->context['total'] === 2;
        });
    }

    public function test_no_failed_job_alert_when_table_is_empty(): void
    {
        Event::fake([AdminOperationalAlert::class]);
        $this->runCommand();

        $types = $this->firedAlertTypes();
        $this->assertNotContains('queue_jobs_failed', $types);
    }

    // ── Stale reserved jobs ───────────────────────────────────────────────────

    public function test_stale_reserved_job_fires_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        // reserved_at is a Unix timestamp; 10 minutes ago is well past QUEUE_STALE_SECONDS=300
        DB::table('jobs')->insert([
            'queue'        => 'critical',
            'payload'      => '{}',
            'attempts'     => 1,
            'reserved_at'  => now()->subMinutes(10)->timestamp,
            'available_at' => now()->subMinutes(10)->timestamp,
            'created_at'   => now()->subMinutes(10)->timestamp,
        ]);

        $this->runCommand();

        Event::assertDispatched(AdminOperationalAlert::class, function ($e) {
            return $e->alertType === 'queue_jobs_stale_reserved';
        });
    }

    public function test_recently_reserved_job_does_not_fire_stale_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        // reserved 30 seconds ago — within retry_after=90s, well within QUEUE_STALE_SECONDS=300
        DB::table('jobs')->insert([
            'queue'        => 'critical',
            'payload'      => '{}',
            'attempts'     => 1,
            'reserved_at'  => now()->subSeconds(30)->timestamp,
            'available_at' => now()->subSeconds(30)->timestamp,
            'created_at'   => now()->subSeconds(30)->timestamp,
        ]);

        $this->runCommand();

        $types = $this->firedAlertTypes();
        $this->assertNotContains('queue_jobs_stale_reserved', $types);
    }

    public function test_unreserved_job_does_not_fire_stale_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        DB::table('jobs')->insert([
            'queue'        => 'low',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->timestamp,
            'created_at'   => now()->timestamp,
        ]);

        $this->runCommand();

        $types = $this->firedAlertTypes();
        $this->assertNotContains('queue_jobs_stale_reserved', $types);
    }

    // ── Abandoned transfer_outbox leases ──────────────────────────────────────

    private function insertSettlementAndLine(): array
    {
        $sid = (int) DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_alert_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => 10000, 'currency' => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500, 'fund_bps' => 4500, 'ops_bps' => 1000,
            'artist_cents'             => 4500, 'fund_cents' => 4500, 'ops_cents' => 1000,
            'status'                   => 'pending',
            'created_at'               => now(), 'updated_at' => now(),
        ]);

        $lid = (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'  => $sid,
            'recipient_type' => 'artist',
            'amount_cents'   => 4500, 'currency' => 'EUR', 'weight' => 1,
            'status'         => 'processing',
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        return compact('sid', 'lid');
    }

    public function test_abandoned_outbox_lease_fires_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $f = $this->insertSettlementAndLine();

        DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'     => $f['lid'],
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 4500, 'currency' => 'EUR',
            'stripe_idempotency_key' => 'idem_abandoned',
            'status'                 => 'processing',
            'processing_started_at'  => now()->subMinutes(10),
            'attempt'                => 0,
            'created_at'             => now(), 'updated_at' => now(),
        ]);

        $this->runCommand();

        Event::assertDispatched(AdminOperationalAlert::class, function ($e) {
            return $e->alertType === 'outbox_lease_abandoned' && $e->context['count'] === 1;
        });
    }

    public function test_active_outbox_lease_does_not_fire_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $f = $this->insertSettlementAndLine();

        DB::table('transfer_outbox')->insertGetId([
            'settlement_line_id'     => $f['lid'],
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 4500, 'currency' => 'EUR',
            'stripe_idempotency_key' => 'idem_active',
            'status'                 => 'processing',
            'processing_started_at'  => now()->subSeconds(30),
            'attempt'                => 0,
            'created_at'             => now(), 'updated_at' => now(),
        ]);

        $this->runCommand();

        $types = $this->firedAlertTypes();
        $this->assertNotContains('outbox_lease_abandoned', $types);
    }

    // ── Abandoned transfer_reversal_order leases ──────────────────────────────

    private function insertReversalFixtures(): int
    {
        $f   = $this->insertSettlementAndLine();
        $rid = (int) DB::table('refunds')->insertGetId([
            'stripe_refund_id'      => 're_' . uniqid(),
            'settlement_id'         => $f['sid'],
            'amount_cents'          => 4500, 'currency' => 'EUR',
            'is_partial'            => false,
            'refund_status'         => 'succeeded',
            'reconciliation_status' => 'pending',
            'created_at'            => now(), 'updated_at' => now(),
        ]);
        $rlid = (int) DB::table('refund_lines')->insertGetId([
            'refund_id'          => $rid,
            'settlement_line_id' => $f['lid'],
            'amount_cents'       => 4500, 'currency' => 'EUR',
            'recipient_type'     => 'artist',
            'status'             => 'pending',
            'created_at'         => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('transfer_reversal_orders')->insertGetId([
            'refund_line_id'     => $rlid,
            'stripe_transfer_id' => 'tr_for_reversal',
            'amount_cents'       => 4500, 'currency' => 'EUR',
            'idempotency_key'    => 'rev_idem_' . uniqid(),
            'status'             => 'processing',
            'attempt'            => 0,
            'created_at'         => now(), 'updated_at' => now(),
        ]);
    }

    public function test_abandoned_reversal_lease_fires_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $orderId = $this->insertReversalFixtures();
        DB::table('transfer_reversal_orders')->where('id', $orderId)->update([
            'processing_started_at' => now()->subMinutes(10),
        ]);

        $this->runCommand();

        Event::assertDispatched(AdminOperationalAlert::class, function ($e) {
            return $e->alertType === 'reversal_lease_abandoned' && $e->context['count'] === 1;
        });
    }

    public function test_active_reversal_lease_does_not_fire_alert(): void
    {
        Event::fake([AdminOperationalAlert::class]);

        $orderId = $this->insertReversalFixtures();
        DB::table('transfer_reversal_orders')->where('id', $orderId)->update([
            'processing_started_at' => now()->subSeconds(30),
        ]);

        $this->runCommand();

        $types = $this->firedAlertTypes();
        $this->assertNotContains('reversal_lease_abandoned', $types);
    }
}
