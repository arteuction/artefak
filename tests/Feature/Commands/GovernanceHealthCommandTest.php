<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 173 — GovernanceHealthCommand (governance:health) tests.
 *
 * Tests: command registration, exit-code contract, JSON output shape,
 * schema-exists checks pass, policy checks reflect actual DB state,
 * escalation/erasure checks, totals consistency.
 */
final class GovernanceHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->artisan('governance:health --help')->assertExitCode(0);
    }

    public function test_command_runs_without_crashing(): void
    {
        // Fresh DB has tables but no policies → may exit 1, must never crash.
        $exitCode = Artisan::call('governance:health');
        $this->assertContains($exitCode, [0, 1], 'governance:health must exit 0 or 1');
    }

    public function test_json_format_returns_parseable_json(): void
    {
        Artisan::call('governance:health', ['--format' => 'json']);
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
        Artisan::call('governance:health', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('check', $check);
            $this->assertArrayHasKey('pass', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertIsBool($check['pass']);
            $this->assertIsString($check['check']);
        }
    }

    public function test_schema_checks_pass_on_fresh_database(): void
    {
        // RefreshDatabase runs migrations so all tables exist.
        Artisan::call('governance:health', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        foreach (['governance_policies', 'user_consents', 'dispute_escalations'] as $table) {
            $schemaCheck = collect($decoded['checks'])
                ->first(fn ($c) => str_contains($c['check'], "Schema: {$table} exists"));
            $this->assertNotNull($schemaCheck, "Schema check for {$table} must be present");
            $this->assertTrue($schemaCheck['pass'], "{$table} table must exist after migrations");
        }
    }

    public function test_escalation_check_passes_on_empty_database(): void
    {
        // No escalations → stale-escalation check passes.
        Artisan::call('governance:health', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $escalationCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'Escalations'));
        $this->assertNotNull($escalationCheck, 'Escalations check must be present');
        $this->assertTrue($escalationCheck['pass'], 'Escalations check should pass on empty DB');
    }

    public function test_erasure_check_passes_on_empty_database(): void
    {
        // No domain_events → stuck-erasure check passes.
        Artisan::call('governance:health', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $erasureCheck = collect($decoded['checks'])
            ->first(fn ($c) => str_contains($c['check'], 'Erasure'));
        $this->assertNotNull($erasureCheck, 'Erasure check must be present');
        $this->assertTrue($erasureCheck['pass'], 'Erasure check should pass on empty DB');
    }

    public function test_policy_check_fails_when_no_active_policies(): void
    {
        // governance_policies table is empty → policy checks fail → exit 1.
        $this->artisan('governance:health')->assertExitCode(1);
    }

    public function test_policy_check_passes_when_active_policies_exist(): void
    {
        $this->seedActivePolicies();

        Artisan::call('governance:health', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        foreach (['terms_of_service', 'privacy_policy'] as $type) {
            $policyCheck = collect($decoded['checks'])
                ->first(fn ($c) => str_contains($c['check'], $type));
            $this->assertNotNull($policyCheck);
            $this->assertTrue($policyCheck['pass'], "Policy check for {$type} should pass");
        }
    }

    public function test_totals_are_consistent(): void
    {
        Artisan::call('governance:health', ['--format' => 'json']);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(count($decoded['checks']), $decoded['total']);
        $this->assertSame($decoded['pass'] + $decoded['fail'], $decoded['total']);
    }

    public function test_exit_zero_when_all_checks_pass(): void
    {
        $this->seedActivePolicies();

        $this->artisan('governance:health')->assertExitCode(0);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedActivePolicies(): void
    {
        \Illuminate\Support\Facades\DB::table('governance_policies')->insert([
            [
                'type'           => 'terms_of_service',
                'version'        => '1.0',
                'effective_from' => '2026-01-01',
                'content_text'   => 'ToS v1',
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'type'           => 'privacy_policy',
                'version'        => '1.0',
                'effective_from' => '2026-01-01',
                'content_text'   => 'Privacy v1',
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);
    }
}
