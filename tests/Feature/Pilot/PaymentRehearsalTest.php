<?php

declare(strict_types=1);

namespace Tests\Feature\Pilot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 156 — Payment rehearsal command verification.
 */
final class PaymentRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_rehearsal_command_is_registered(): void
    {
        $this->artisan('pilot:payment-rehearsal --help')->assertExitCode(0);
    }

    public function test_command_passes_on_empty_database(): void
    {
        $this->artisan('pilot:payment-rehearsal')->assertExitCode(0);
    }

    public function test_command_outputs_json(): void
    {
        $result = $this->artisan('pilot:payment-rehearsal --format=json');
        $result->assertExitCode(0);
        $output = $result->output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertGreaterThan(0, $decoded['summary']['passed']);
        $this->assertSame(0, $decoded['summary']['failed']);
    }

    public function test_detects_null_idempotency_key(): void
    {
        // Seed a minimal settlement + line to satisfy FK constraints
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_rehearsal_test',
            'stripe_event_id'          => 'evt_rehearsal_test',
            'gross_cents'              => 1000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 450,
            'fund_cents'               => 450,
            'ops_cents'                => 100,
            'status'                   => 'completed',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $lineId = DB::table('settlement_lines')->insertGetId([
            'settlement_id'  => $settlementId,
            'recipient_type' => 'artist',
            'amount_cents'   => 450,
            'currency'       => 'EUR',
            'weight'         => 1,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Insert a ledger row with null idempotency_key (bypasses the app layer)
        DB::statement("
            INSERT INTO ledger_entries
              (settlement_id, settlement_line_id, type, amount_cents, currency, idempotency_key, created_at, updated_at)
            VALUES (?, ?, 'credit', 450, 'EUR', NULL, NOW(), NOW())
        ", [$settlementId, $lineId]);

        $result = $this->artisan('pilot:payment-rehearsal --format=json');
        $output = json_decode($result->output(), true);

        $ledgerCheck = collect($output['checks'])
            ->firstWhere('check', 'Ledger idempotency keys');

        $this->assertSame('fail', $ledgerCheck['status']);
        $result->assertExitCode(1);
    }

    public function test_detects_failed_jobs(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => 'test-uuid-' . uniqid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => '{}',
            'exception'  => 'Test exception',
            'failed_at'  => now(),
        ]);

        $result = $this->artisan('pilot:payment-rehearsal --format=json');
        $output = json_decode($result->output(), true);

        $jobCheck = collect($output['checks'])
            ->firstWhere('check', 'Failed jobs queue');

        $this->assertContains($jobCheck['status'], ['warn', 'fail']);
    }

    public function test_runbooks_exist(): void
    {
        $this->assertFileExists(base_path('docs/runbooks/rollback.md'));
        $this->assertFileExists(base_path('docs/runbooks/operations.md'));
    }

    public function test_rollback_runbook_covers_financial_invariants(): void
    {
        $content = file_get_contents(base_path('docs/runbooks/rollback.md'));
        $this->assertStringContainsString('append-only', $content);
        $this->assertStringContainsString('idempotency_key', $content);
        $this->assertStringContainsString('ledger_entries', $content);
    }

    public function test_operations_runbook_covers_all_scenarios(): void
    {
        $content = file_get_contents(base_path('docs/runbooks/operations.md'));
        $this->assertStringContainsString('Stuck payment', $content);
        $this->assertStringContainsString('Unmatched ledger', $content);
        $this->assertStringContainsString('Failed Stripe transfer', $content);
        $this->assertStringContainsString('Disputed transaction', $content);
        $this->assertStringContainsString('Compromised credentials', $content);
        $this->assertStringContainsString('GDPR', $content);
        $this->assertStringContainsString('Pilot transaction limits', $content);
    }

    public function test_staging_docker_compose_exists(): void
    {
        $this->assertFileExists(base_path('docker-compose.yml'));
        $content = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringContainsString('mariadb', $content);
        $this->assertStringContainsString('redis', $content);
        $this->assertStringContainsString('meilisearch', $content);
        $this->assertStringContainsString('minio', $content);
        $this->assertStringContainsString('reverb', $content);
        $this->assertStringContainsString('stripe', $content);
    }
}
