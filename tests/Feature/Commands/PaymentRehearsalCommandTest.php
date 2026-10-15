<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 176 — PaymentRehearsalCommand (pilot:payment-rehearsal) tests.
 *
 * Tests: command registration, exit-code contract, JSON output shape,
 * required keys in each check entry, three-ledger check passes, totals
 * consistency, and exit 0 on empty clean database.
 */
final class PaymentRehearsalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('pilot:payment-rehearsal --help')->assertExitCode(0);
    }

    public function test_command_runs_without_crashing(): void
    {
        $exitCode = Artisan::call('pilot:payment-rehearsal');
        $this->assertContains($exitCode, [0, 1], 'command must exit 0 or 1');
    }

    public function test_json_format_returns_parseable_json(): void
    {
        Artisan::call('pilot:payment-rehearsal', ['--format' => 'json']);
        $output  = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'output must be valid JSON');
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertIsArray($decoded['checks']);
        $this->assertArrayHasKey('passed', $decoded['summary']);
        $this->assertArrayHasKey('failed', $decoded['summary']);
        $this->assertArrayHasKey('warned', $decoded['summary']);
        $this->assertArrayHasKey('total', $decoded['summary']);
    }

    public function test_check_entries_have_required_keys(): void
    {
        Artisan::call('pilot:payment-rehearsal', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('check', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertContains($check['status'], ['pass', 'warn', 'fail']);
        }
    }

    public function test_three_ledgers_check_passes_after_migrations(): void
    {
        // RefreshDatabase runs all migrations so all three ledger tables exist.
        Artisan::call('pilot:payment-rehearsal', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $ledgerCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'Three ledgers'));
        $this->assertNotNull($ledgerCheck, 'Three ledgers check must be present');
        $this->assertSame('pass', $ledgerCheck['status'], 'Three ledgers should exist after migrations');
    }

    public function test_ledger_idempotency_check_passes_on_empty_database(): void
    {
        Artisan::call('pilot:payment-rehearsal', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $keyCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'Ledger idempotency keys'));
        $this->assertNotNull($keyCheck, 'Ledger idempotency keys check must be present');
        $this->assertSame('pass', $keyCheck['status'], 'Empty ledger should pass idempotency check');
    }

    public function test_donation_ledger_check_always_passes(): void
    {
        Artisan::call('pilot:payment-rehearsal', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $donationCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'Donation ledger'));
        $this->assertNotNull($donationCheck, 'Donation ledger check must be present');
        $this->assertSame('pass', $donationCheck['status']);
    }

    public function test_totals_are_consistent(): void
    {
        Artisan::call('pilot:payment-rehearsal', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $summary = $decoded['summary'];
        $this->assertSame(count($decoded['checks']), $summary['total']);
        $this->assertSame($summary['passed'] + $summary['failed'] + $summary['warned'], $summary['total']);
    }

    public function test_exits_zero_on_clean_empty_database(): void
    {
        // On a fresh migrated DB with no data the command should find no failures.
        $this->artisan('pilot:payment-rehearsal')->assertExitCode(0);
    }
}
