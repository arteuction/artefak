<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 172 — SupplyChainAuditCommand (supply-chain:audit) tests.
 *
 * Verifies: command registration, text/JSON exit codes, JSON output shape,
 * presence of SLSA and OpenSSF check categories, APP_KEY check passes in the
 * test environment, and totals consistency.
 */
final class SupplyChainAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('supply-chain:audit --help')->assertExitCode(0);
    }

    public function test_command_runs_without_error_in_test_environment(): void
    {
        // composer.json, composer.lock, and vendor/ all exist in the project.
        // Composer audit may report known advisories (suppressed via OSV) but
        // that only affects one check. Overall exit code is expected to be 0
        // unless composer audit itself finds unresolved high-severity issues.
        // We assert the command completes (not crash), allowing exit 0 or 1.
        $exitCode = Artisan::call('supply-chain:audit');
        $this->assertContains($exitCode, [0, 1], 'command must exit 0 or 1 (never crash)');
    }

    public function test_json_format_returns_parseable_json(): void
    {
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $output  = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'output must be valid JSON');
        $this->assertArrayHasKey('total', $decoded);
        $this->assertArrayHasKey('pass', $decoded);
        $this->assertArrayHasKey('fail', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertIsArray($decoded['checks']);
    }

    public function test_check_entries_have_required_keys(): void
    {
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $output  = Artisan::output();
        $decoded = json_decode($output, true);

        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('check', $check);
            $this->assertArrayHasKey('pass', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertIsBool($check['pass']);
        }
    }

    public function test_slsa_composer_files_check_passes(): void
    {
        // composer.json and composer.lock exist in every working checkout.
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $composerJsonCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'composer.json present'));
        $this->assertNotNull($composerJsonCheck, 'SLSA composer.json check must be present');
        $this->assertTrue($composerJsonCheck['pass'], 'composer.json should be found');

        $composerLockCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'composer.lock present'));
        $this->assertNotNull($composerLockCheck, 'SLSA composer.lock check must be present');
        $this->assertTrue($composerLockCheck['pass'], 'composer.lock should be found');
    }

    public function test_app_key_check_passes_in_test_environment(): void
    {
        // The test environment always has an APP_KEY set.
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $keyCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'APP_KEY is set'));
        $this->assertNotNull($keyCheck, 'APP_KEY check must be present');
        $this->assertTrue($keyCheck['pass'], 'APP_KEY should be set in test environment');
    }

    public function test_php_version_constraint_check_passes(): void
    {
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $phpCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'PHP version constraint present'));
        $this->assertNotNull($phpCheck, 'PHP version constraint check must be present');
        $this->assertTrue($phpCheck['pass'], 'composer.json should declare a PHP version constraint');
    }

    public function test_totals_are_consistent(): void
    {
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(count($decoded['checks']), $decoded['total']);
        $this->assertSame($decoded['pass'] + $decoded['fail'], $decoded['total']);
    }

    public function test_app_debug_check_skipped_outside_production(): void
    {
        // In test environment the APP_DEBUG production check is skipped.
        Artisan::call('supply-chain:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $debugCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'APP_DEBUG'));
        $this->assertNotNull($debugCheck, 'APP_DEBUG check should appear in output');
        // In non-production environments it is marked as pass (skipped/info)
        $this->assertTrue($debugCheck['pass'], 'APP_DEBUG check should pass outside production');
    }
}
