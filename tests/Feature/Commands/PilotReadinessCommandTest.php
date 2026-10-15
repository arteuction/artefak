<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 176 — PilotReadinessCommand (pilot:readiness) tests.
 *
 * Tests: command registration, exit-code contract, JSON output shape,
 * required keys in each check entry, database/migrations pass after
 * RefreshDatabase, critical tables check, totals consistency.
 */
final class PilotReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('pilot:readiness --help')->assertExitCode(0);
    }

    public function test_command_runs_without_crashing(): void
    {
        $exitCode = Artisan::call('pilot:readiness');
        $this->assertContains($exitCode, [0, 1], 'pilot:readiness must exit 0 or 1');
    }

    public function test_json_format_returns_parseable_json(): void
    {
        Artisan::call('pilot:readiness', ['--format' => 'json']);
        $output  = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'output must be valid JSON');
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertIsArray($decoded['checks']);
        $this->assertArrayHasKey('passed', $decoded['summary']);
        $this->assertArrayHasKey('warned', $decoded['summary']);
        $this->assertArrayHasKey('failed', $decoded['summary']);
        $this->assertArrayHasKey('total', $decoded['summary']);
    }

    public function test_check_entries_have_required_keys(): void
    {
        Artisan::call('pilot:readiness', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('check', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertContains($check['status'], ['pass', 'warn', 'fail']);
        }
    }

    public function test_database_check_passes(): void
    {
        Artisan::call('pilot:readiness', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $dbCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains(strtolower($c['check']), 'database'));
        $this->assertNotNull($dbCheck, 'Database check must be present');
        $this->assertSame('pass', $dbCheck['status'], 'Database should be reachable in test env');
    }

    public function test_migrations_check_passes(): void
    {
        // RefreshDatabase runs all migrations, so no pending migrations remain.
        Artisan::call('pilot:readiness', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $migCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains(strtolower($c['check']), 'migration'));
        $this->assertNotNull($migCheck, 'Migrations check must be present');
        $this->assertSame('pass', $migCheck['status'], 'No pending migrations after RefreshDatabase');
    }

    public function test_critical_tables_check_passes(): void
    {
        Artisan::call('pilot:readiness', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $tablesCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains(strtolower($c['check']), 'critical tables'));
        $this->assertNotNull($tablesCheck, 'Critical tables check must be present');
        $this->assertSame('pass', $tablesCheck['status'], 'All critical tables exist after migrations');
    }

    public function test_totals_are_consistent(): void
    {
        Artisan::call('pilot:readiness', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $summary = $decoded['summary'];
        $this->assertSame(count($decoded['checks']), $summary['total']);
        $this->assertSame($summary['passed'] + $summary['warned'] + $summary['failed'], $summary['total']);
    }
}
