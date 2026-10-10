<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Pulse\Facades\Pulse;

/**
 * Phase 100 — Pilot readiness checklist.
 *
 * Verifies all critical subsystems before a pilot go-live.
 * Exit code 0 = all checks pass; non-zero = one or more failures.
 *
 * Usage:
 *   php artisan pilot:readiness
 *   php artisan pilot:readiness --format=json
 */
final class PilotReadinessCommand extends Command
{
    protected $signature = 'pilot:readiness {--format=text : Output format (text|json)}';
    protected $description = 'Run the pilot go-live readiness checklist';

    private array $results = [];

    public function handle(): int
    {
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkCriticalTables();
        $this->checkEnvVars();
        $this->checkStorageWritable();
        $this->checkHealthEndpoint();
        $this->checkSellNowStripeColumns();
        $this->checkReverbConfig();
        $this->checkWebhookEventsTable();

        $passed  = count(array_filter($this->results, fn ($r) => $r['status'] === 'pass'));
        $failed  = count(array_filter($this->results, fn ($r) => $r['status'] === 'fail'));
        $warned  = count(array_filter($this->results, fn ($r) => $r['status'] === 'warn'));
        $total   = count($this->results);

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'summary' => compact('passed', 'failed', 'warned', 'total'),
                'checks'  => $this->results,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->line('  <fg=white;options=bold>ARTeuCtion Pilot Readiness</>');
            $this->newLine();
            foreach ($this->results as $r) {
                $icon = match ($r['status']) {
                    'pass' => '<fg=green>✓</>',
                    'warn' => '<fg=yellow>!</>',
                    default => '<fg=red>✗</>',
                };
                $this->line("  {$icon}  {$r['check']}" . ($r['detail'] ? " — {$r['detail']}" : ''));
            }
            $this->newLine();
            $this->line("  Passed: {$passed}/{$total}" . ($warned ? "  Warnings: {$warned}" : '') . ($failed ? "  <fg=red>Failed: {$failed}</>" : ''));
            $this->newLine();
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->recordPass('Database connection', DB::connection()->getDatabaseName());
        } catch (\Exception $e) {
            $this->recordFail('Database connection', $e->getMessage());
        }
    }

    private function checkMigrations(): void
    {
        try {
            $migrator   = app('migrator');
            $files      = $migrator->getMigrationFiles(database_path('migrations'));
            $ran        = $migrator->getRepository()->getRan();
            $pending    = collect($files)->keys()->diff($ran);

            if ($pending->isEmpty()) {
                $this->recordPass('Migrations', 'all applied');
            } else {
                $this->recordFail('Migrations', "{$pending->count()} pending: " . $pending->implode(', '));
            }
        } catch (\Exception $e) {
            $this->recordWarn('Migrations', 'could not check: ' . $e->getMessage());
        }
    }

    private function checkCriticalTables(): void
    {
        $tables = [
            'users', 'artworks', 'art_lots', 'auctions', 'galleries',
            'ledger_entries', 'donations', 'impact_events',
            'activity_log', 'pulse_entries', 'transfer_outbox',
        ];

        $missing = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            $this->recordPass('Critical tables', count($tables) . ' tables present');
        } else {
            $this->recordFail('Critical tables', 'missing: ' . implode(', ', $missing));
        }
    }

    private function checkEnvVars(): void
    {
        $required = [
            'APP_KEY', 'APP_URL', 'DB_DATABASE',
            'STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET',
        ];
        $missing = array_filter($required, fn ($k) => empty(env($k)));

        if (empty($missing)) {
            $this->recordPass('Environment variables', count($required) . ' required vars set');
        } else {
            $this->recordFail('Environment variables', 'missing: ' . implode(', ', $missing));
        }

        // Warn if still using example/test keys in non-local env
        if (app()->environment('production')) {
            if (str_starts_with((string) env('STRIPE_KEY', ''), 'sk_test_')) {
                $this->recordWarn('Stripe key', 'test key detected in production environment');
            }
        }
    }

    private function checkStorageWritable(): void
    {
        $paths = [storage_path('logs'), storage_path('framework/cache')];
        $unwritable = array_filter($paths, fn ($p) => ! is_writable($p));

        if (empty($unwritable)) {
            $this->recordPass('Storage writable', 'logs and cache directories writable');
        } else {
            $this->recordFail('Storage writable', 'not writable: ' . implode(', ', $unwritable));
        }
    }

    private function checkHealthEndpoint(): void
    {
        // Check Spatie Health checks are registered
        try {
            $checks = app(\Spatie\Health\Health::class)->registeredChecks();
            $this->recordPass('Health checks', count($checks) . ' checks registered');
        } catch (\Exception $e) {
            $this->recordWarn('Health checks', 'could not enumerate: ' . $e->getMessage());
        }
    }

    private function checkSellNowStripeColumns(): void
    {
        $columns = ['stripe_checkout_session_id', 'stripe_payment_intent_id'];
        $missing = array_filter($columns, fn ($c) => ! Schema::hasColumn('sell_now_offers', $c));

        if (empty($missing)) {
            $this->recordPass('SellNow Stripe columns', 'both columns present on sell_now_offers');
        } else {
            $this->recordFail('SellNow Stripe columns', 'missing: ' . implode(', ', $missing));
        }
    }

    private function checkReverbConfig(): void
    {
        $required = ['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'];
        $missing  = array_filter($required, fn ($k) => empty(env($k)));

        if (empty($missing)) {
            $this->recordPass('Reverb WebSocket', count($required) . ' env vars set');
        } else {
            $this->recordWarn('Reverb WebSocket', 'missing: ' . implode(', ', $missing) . ' — live auction bidding will not work');
        }
    }

    private function checkWebhookEventsTable(): void
    {
        if (Schema::hasTable('webhook_events')) {
            $this->recordPass('Webhook events table', 'present');
        } else {
            $this->recordFail('Webhook events table', 'missing — Stripe webhooks cannot be processed');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recordPass(string $check, string $detail = ''): void
    {
        $this->results[] = ['check' => $check, 'status' => 'pass', 'detail' => $detail];
    }

    private function recordFail(string $check, string $detail = ''): void
    {
        $this->results[] = ['check' => $check, 'status' => 'fail', 'detail' => $detail];
    }

    private function recordWarn(string $check, string $detail = ''): void
    {
        $this->results[] = ['check' => $check, 'status' => 'warn', 'detail' => $detail];
    }
}
