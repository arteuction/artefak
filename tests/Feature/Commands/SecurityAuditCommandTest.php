<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 176 — SecurityAuditCommand (security:audit) tests.
 *
 * Tests: command registration, exit-code contract, JSON output shape,
 * required keys in each check entry, APP_KEY check passes in test env,
 * debug check passes outside production, totals consistency.
 */
final class SecurityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('security:audit --help')->assertExitCode(0);
    }

    public function test_command_runs_without_crashing(): void
    {
        $exitCode = Artisan::call('security:audit');
        $this->assertContains($exitCode, [0, 1], 'security:audit must exit 0 or 1');
    }

    public function test_json_format_returns_parseable_json(): void
    {
        Artisan::call('security:audit', ['--format' => 'json']);
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
        Artisan::call('security:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('check', $check);
            $this->assertArrayHasKey('pass', $check);
            $this->assertIsBool($check['pass']);
            $this->assertIsString($check['check']);
        }
    }

    public function test_app_key_check_passes_in_test_environment(): void
    {
        // The test environment always has an APP_KEY set.
        Artisan::call('security:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $keyCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'APP_KEY'));
        $this->assertNotNull($keyCheck, 'APP_KEY check must be present');
        $this->assertTrue($keyCheck['pass'], 'APP_KEY should be set in test environment');
    }

    public function test_debug_check_passes_outside_production(): void
    {
        // In test environment APP_DEBUG=true is allowed (only fails in production).
        Artisan::call('security:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $debugCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'APP_DEBUG'));
        $this->assertNotNull($debugCheck, 'APP_DEBUG check must be present');
        $this->assertTrue($debugCheck['pass'], 'APP_DEBUG check passes outside production');
    }

    public function test_totals_are_consistent(): void
    {
        Artisan::call('security:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(count($decoded['checks']), $decoded['total']);
        $this->assertSame($decoded['pass'] + $decoded['fail'], $decoded['total']);
    }

    public function test_sanctum_guard_check_is_present(): void
    {
        Artisan::call('security:audit', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $sanctumCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'Sanctum'));
        $this->assertNotNull($sanctumCheck, 'Sanctum guard check must be present');
    }
}
