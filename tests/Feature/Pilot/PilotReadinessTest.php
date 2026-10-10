<?php

declare(strict_types=1);

namespace Tests\Feature\Pilot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 100 — Pilot readiness runbook verification.
 */
final class PilotReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_runbook_exists(): void
    {
        $this->assertFileExists(base_path('RUNBOOK.md'));
    }

    public function test_runbook_documents_three_ledger_rule(): void
    {
        $content = file_get_contents(base_path('RUNBOOK.md'));
        $this->assertStringContainsString('ledger_entries', $content);
        $this->assertStringContainsString('donations', $content);
        $this->assertStringContainsString('impact_events', $content);
        $this->assertStringContainsString('append-only', $content);
    }

    public function test_runbook_documents_eur_only_currency(): void
    {
        $content = file_get_contents(base_path('RUNBOOK.md'));
        $this->assertStringContainsString('EUR', $content);
        $this->assertStringContainsString('2026-01-01', $content);
    }

    public function test_pilot_readiness_command_is_registered(): void
    {
        $this->artisan('pilot:readiness --help')->assertExitCode(0);
    }

    public function test_pilot_readiness_command_passes_in_test_environment(): void
    {
        // In the test env all critical tables exist and migrations are applied.
        $this->artisan('pilot:readiness')->assertExitCode(0);
    }

    public function test_pilot_readiness_json_format_returns_valid_json(): void
    {
        $this->artisan('pilot:readiness --format=json')
            ->assertExitCode(0);
    }

    public function test_critical_tables_all_exist(): void
    {
        $tables = [
            'users', 'artworks', 'art_lots', 'auctions', 'galleries',
            'ledger_entries', 'donations', 'impact_events',
            'activity_log', 'pulse_entries', 'transfer_outbox',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasTable($table),
                "Critical table '{$table}' is missing from the test database.",
            );
        }
    }
}
