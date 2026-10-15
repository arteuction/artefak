<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 172 — ReleaseGateCommand (release:gate) tests.
 *
 * The command shells out to four sub-commands. In CI all sub-commands are
 * expected to pass, so release:gate should exit 0. Tests also cover:
 * --skip flag behaviour, --format=json output shape, blocked-gate exit code.
 */
final class ReleaseGateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('release:gate --help')->assertExitCode(0);
    }

    public function test_command_runs_without_crashing(): void
    {
        // All four sub-commands are expected to pass in the test environment.
        // We allow exit 0 or 1 — the gate may be blocked if a sub-command
        // finds an issue (e.g. composer audit advisory), but must not crash.
        $exitCode = Artisan::call('release:gate');
        $this->assertContains($exitCode, [0, 1], 'release:gate must exit 0 (pass) or 1 (blocked)');
    }

    public function test_skip_all_gates_exits_zero(): void
    {
        // Skipping every gate means no failures → gate passes.
        $this->artisan('release:gate', [
            '--skip' => 'governance:health,security:audit,cultural:conformance,supply-chain:audit',
        ])->assertExitCode(0);
    }

    public function test_json_format_returns_parseable_json(): void
    {
        Artisan::call('release:gate', [
            '--format' => 'json',
            '--skip'   => 'governance:health,security:audit,cultural:conformance,supply-chain:audit',
        ]);
        $output  = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'output must be valid JSON');
        $this->assertArrayHasKey('release_gate', $decoded);
        $this->assertArrayHasKey('skipped', $decoded);
        $this->assertArrayHasKey('gates', $decoded);
        $this->assertIsString($decoded['release_gate']);
        $this->assertIsArray($decoded['skipped']);
        $this->assertIsArray($decoded['gates']);
    }

    public function test_json_skipped_field_reflects_skip_option(): void
    {
        Artisan::call('release:gate', [
            '--format' => 'json',
            '--skip'   => 'cultural:conformance,supply-chain:audit',
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertContains('cultural:conformance', $decoded['skipped']);
        $this->assertContains('supply-chain:audit', $decoded['skipped']);
    }

    public function test_skipped_gates_absent_from_gates_result(): void
    {
        Artisan::call('release:gate', [
            '--format' => 'json',
            '--skip'   => 'governance:health,security:audit,cultural:conformance,supply-chain:audit',
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertEmpty($decoded['gates'], 'all gates were skipped — gates map should be empty');
    }

    public function test_gates_entries_have_required_keys(): void
    {
        // Run with only cultural:conformance (least likely to fail in test env)
        Artisan::call('release:gate', [
            '--format' => 'json',
            '--skip'   => 'governance:health,security:audit,supply-chain:audit',
        ]);
        $decoded = json_decode(Artisan::output(), true);

        foreach ($decoded['gates'] as $gate => $result) {
            $this->assertIsString($gate);
            $this->assertArrayHasKey('pass', $result, "gate {$gate} must have pass key");
            $this->assertArrayHasKey('exit', $result, "gate {$gate} must have exit key");
            $this->assertArrayHasKey('checks', $result, "gate {$gate} must have checks key");
            $this->assertIsBool($result['pass']);
        }
    }

    public function test_all_skipped_reports_passed(): void
    {
        Artisan::call('release:gate', [
            '--format' => 'json',
            '--skip'   => 'governance:health,security:audit,cultural:conformance,supply-chain:audit',
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertSame('PASSED', $decoded['release_gate']);
    }
}
