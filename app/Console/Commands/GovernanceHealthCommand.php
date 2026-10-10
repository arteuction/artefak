<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 140 — Governance health check.
 *
 * Verifies the governance subsystem: active policies, recent consents,
 * open dispute escalations, pending erasure requests, and schema presence.
 *
 * Usage:
 *   php artisan governance:health
 *   php artisan governance:health --format=json
 */
final class GovernanceHealthCommand extends Command
{
    protected $signature   = 'governance:health {--format=text : Output format (text|json)}';
    protected $description = 'Governance subsystem health check';

    private array $results = [];

    public function handle(): int
    {
        $this->checkSchemasExist();
        $this->checkActivePolicies();
        $this->checkOpenEscalations();
        $this->checkErasureEvents();

        return $this->report();
    }

    private function checkSchemasExist(): void
    {
        foreach (['governance_policies', 'user_consents', 'dispute_escalations'] as $table) {
            $this->record("Schema: {$table} exists", Schema::hasTable($table));
        }
    }

    private function checkActivePolicies(): void
    {
        foreach (['terms_of_service', 'privacy_policy'] as $type) {
            $count = DB::table('governance_policies')
                ->where('type', $type)
                ->where('is_active', true)
                ->count();
            $this->record("Policy: active {$type}", $count >= 1,
                $count === 0 ? 'no active version — users cannot consent' : "{$count} active");
        }
    }

    private function checkOpenEscalations(): void
    {
        $open = DB::table('dispute_escalations')
            ->where('outcome', 'pending')
            ->where('created_at', '<', now()->subDays(7))
            ->count();
        $this->record('Escalations: no stale (>7d) open escalations', $open === 0,
            $open > 0 ? "{$open} stale" : '');
    }

    private function checkErasureEvents(): void
    {
        // Erasure events that have been pending for > 30 days indicate a processing failure
        $stuck = DB::table('domain_events')
            ->where('event_type', 'user.erased')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays(30))
            ->count();
        $this->record('Erasure: no stuck (>30d) erasure events', $stuck === 0,
            $stuck > 0 ? "{$stuck} stuck" : '');
    }

    private function record(string $check, bool $pass, string $detail = ''): void
    {
        $this->results[] = ['check' => $check, 'pass' => $pass, 'detail' => $detail];
    }

    private function report(): int
    {
        $failures = array_filter($this->results, fn ($r) => ! $r['pass']);

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'total'  => count($this->results),
                'pass'   => count($this->results) - count($failures),
                'fail'   => count($failures),
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT));
            return count($failures) > 0 ? 1 : 0;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Governance Health</>');
        $this->newLine();
        foreach ($this->results as $r) {
            $icon   = $r['pass'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $label  = $r['pass'] ? $r['check'] : "<fg=red>{$r['check']}</>";
            $detail = $r['detail'] ? " <fg=gray>({$r['detail']})</>" : '';
            $this->line("  {$icon}  {$label}{$detail}");
        }
        $this->newLine();

        if (count($failures) > 0) {
            $this->error('Governance health FAILED.');
            return 1;
        }
        $this->info('Governance health OK.');
        return 0;
    }
}
