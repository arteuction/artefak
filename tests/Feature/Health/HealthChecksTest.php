<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Checks\FailedDomainEventsCheck;
use App\Checks\OutboxBacklogCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 87 — Spatie Health custom check tests.
 */
final class HealthChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    // ── OutboxBacklogCheck ────────────────────────────────────────────────────

    public function test_outbox_backlog_check_passes_with_empty_table(): void
    {
        $result = (new OutboxBacklogCheck())->run();
        $this->assertTrue($result->status->isOk());
    }

    public function test_outbox_backlog_check_warns_on_first_failure(): void
    {
        DB::table('transfer_outbox')->insert([
            'settlement_line_id'      => 0,
            'stripe_account_id'       => 'acct_test',
            'amount_cents'            => 10000,
            'currency'                => 'EUR',
            'stripe_idempotency_key'  => 'idem_' . uniqid(),
            'status'                  => 'failed',
            'attempt'                 => 3,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $result = (new OutboxBacklogCheck())->run();
        $this->assertTrue($result->status->isWarning());
    }

    public function test_outbox_backlog_check_fails_above_threshold(): void
    {
        for ($i = 0; $i < 10; $i++) {
            DB::table('transfer_outbox')->insert([
                'settlement_line_id'      => $i + 1,
                'stripe_account_id'       => 'acct_test',
                'amount_cents'            => 10000,
                'currency'                => 'EUR',
                'stripe_idempotency_key'  => 'idem_' . uniqid(),
                'status'                  => 'failed',
                'attempt'                 => 3,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);
        }

        $result = (new OutboxBacklogCheck())->run();
        $this->assertTrue($result->status->isFailed());
    }

    // ── FailedDomainEventsCheck ───────────────────────────────────────────────

    public function test_domain_event_check_passes_with_no_failures(): void
    {
        $result = (new FailedDomainEventsCheck())->run();
        $this->assertTrue($result->status->isOk());
    }

    public function test_domain_event_check_warns_on_small_failure_count(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('domain_events')->insert([
                'aggregate_type'  => 'TestAggregate',
                'aggregate_id'    => 1,
                'event_type'      => 'TestEvent',
                'idempotency_key' => 'key_' . uniqid(),
                'payload'         => '{}',
                'status'          => 'failed',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $result = (new FailedDomainEventsCheck())->run();
        $this->assertTrue($result->status->isWarning());
    }

    public function test_domain_event_check_fails_above_threshold(): void
    {
        for ($i = 0; $i < 6; $i++) {
            DB::table('domain_events')->insert([
                'aggregate_type'  => 'TestAggregate',
                'aggregate_id'    => 1,
                'event_type'      => 'TestEvent',
                'idempotency_key' => 'key_' . uniqid(),
                'payload'         => '{}',
                'status'          => 'failed',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $result = (new FailedDomainEventsCheck())->run();
        $this->assertTrue($result->status->isFailed());
    }

    public function test_domain_event_check_fails_on_stuck_events(): void
    {
        DB::table('domain_events')->insert([
            'aggregate_type'  => 'TestAggregate',
            'aggregate_id'    => 1,
            'event_type'      => 'StuckEvent',
            'idempotency_key' => 'key_stuck_' . uniqid(),
            'payload'         => '{}',
            'status'          => 'processing',
            'created_at'      => now()->subMinutes(20),
            'updated_at'      => now()->subMinutes(20),
        ]);

        $result = (new FailedDomainEventsCheck())->run();
        $this->assertTrue($result->status->isFailed());
    }
}
