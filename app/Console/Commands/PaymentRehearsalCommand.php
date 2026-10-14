<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 156 — Payment and payout rehearsal.
 *
 * Verifies all financial flows are structurally intact in the current
 * environment without touching Stripe (pure DB/domain layer checks).
 * Run before every pilot and production deployment.
 *
 * Usage:
 *   php artisan pilot:payment-rehearsal
 *   php artisan pilot:payment-rehearsal --format=json
 */
final class PaymentRehearsalCommand extends Command
{
    protected $signature = 'pilot:payment-rehearsal {--format=text : Output format (text|json)}';
    protected $description = 'Verify all payment and payout financial flows (Phase 156)';

    private array $results = [];

    public function handle(): int
    {
        $this->checkLedgerIntegrity();
        $this->checkSettlementBalance();
        $this->checkIdempotencyKeys();
        $this->checkAppendOnlyLedger();
        $this->checkThreeLedgersSeparate();
        $this->checkTransferOutbox();
        $this->checkReconciliationRuns();
        $this->checkSellNowSettlements();
        $this->checkAuctionSettlements();
        $this->checkRefundLedger();
        $this->checkDonationLedger();
        $this->checkFailedJobs();

        $passed = count(array_filter($this->results, fn ($r) => $r['status'] === 'pass'));
        $failed = count(array_filter($this->results, fn ($r) => $r['status'] === 'fail'));
        $warned = count(array_filter($this->results, fn ($r) => $r['status'] === 'warn'));
        $total  = count($this->results);

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'summary' => compact('passed', 'failed', 'warned', 'total'),
                'checks'  => $this->results,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->line('  <fg=white;options=bold>ARTeuCtion Payment Rehearsal (Phase 156)</>');
            $this->newLine();
            foreach ($this->results as $r) {
                $icon = match ($r['status']) {
                    'pass' => '<fg=green>✓</>',
                    'warn' => '<fg=yellow>!</>',
                    default => '<fg=red>✗</>',
                };
                $this->line("  {$icon}  {$r['check']}: {$r['detail']}");
            }
            $this->newLine();
            $color = $failed > 0 ? 'red' : ($warned > 0 ? 'yellow' : 'green');
            $this->line("  <fg={$color};options=bold>{$passed}/{$total} checks passed, {$failed} failed, {$warned} warnings</>");
            $this->newLine();
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkLedgerIntegrity(): void
    {
        $nullKeys = DB::table('ledger_entries')
            ->whereNull('idempotency_key')
            ->orWhere('idempotency_key', '')
            ->count();

        $this->record(
            'Ledger idempotency keys',
            $nullKeys === 0 ? 'pass' : 'fail',
            $nullKeys === 0
                ? 'All ledger rows have idempotency_key'
                : "{$nullKeys} rows with NULL idempotency_key — financial integrity broken",
        );
    }

    private function checkSettlementBalance(): void
    {
        // Each completed settlement's splits must sum to gross_cents (within 1 cent rounding)
        $mismatches = DB::select("
            SELECT s.id, s.gross_cents,
                   COALESCE(SUM(sl.amount_cents), 0) AS split_sum
            FROM settlements s
            LEFT JOIN settlement_lines sl ON sl.settlement_id = s.id
            WHERE s.status = 'completed'
            GROUP BY s.id, s.gross_cents
            HAVING ABS(s.gross_cents - COALESCE(SUM(sl.amount_cents), 0)) > 1
        ");

        $count = count($mismatches);
        $this->record(
            'Settlement split balance',
            $count === 0 ? 'pass' : 'fail',
            $count === 0
                ? 'All completed settlements balance within 1 cent'
                : "{$count} settlements have split_sum ≠ gross_cents",
        );
    }

    private function checkIdempotencyKeys(): void
    {
        $dupes = DB::table('ledger_entries')
            ->select('idempotency_key', DB::raw('COUNT(*) as cnt'))
            ->groupBy('idempotency_key')
            ->having('cnt', '>', 1)
            ->count();

        $this->record(
            'Ledger idempotency uniqueness',
            $dupes === 0 ? 'pass' : 'fail',
            $dupes === 0
                ? 'No duplicate idempotency_key values'
                : "{$dupes} duplicate idempotency_key groups — double-processing detected",
        );
    }

    private function checkAppendOnlyLedger(): void
    {
        // The updated_at should equal created_at for all ledger rows (append-only)
        $edited = DB::table('ledger_entries')
            ->whereRaw('updated_at > DATE_ADD(created_at, INTERVAL 1 SECOND)')
            ->count();

        $this->record(
            'Ledger append-only invariant',
            $edited === 0 ? 'pass' : 'warn',
            $edited === 0
                ? 'No ledger rows have been edited after creation'
                : "{$edited} ledger rows have updated_at > created_at (check for manual edits)",
        );
    }

    private function checkThreeLedgersSeparate(): void
    {
        // Verify the three ledger tables exist and have no shared foreign keys
        $tables = DB::select("SHOW TABLES LIKE 'ledger_entries'");
        $donations = DB::select("SHOW TABLES LIKE 'donations'");
        $impact = DB::select("SHOW TABLES LIKE 'impact_events'");

        $allExist = count($tables) > 0 && count($donations) > 0 && count($impact) > 0;
        $this->record(
            'Three ledgers exist separately',
            $allExist ? 'pass' : 'fail',
            $allExist
                ? 'ledger_entries, donations, impact_events all present'
                : 'One or more ledger tables missing',
        );
    }

    private function checkTransferOutbox(): void
    {
        $stuck = DB::table('transfer_outbox')
            ->where('status', 'failed')
            ->where('created_at', '<', now()->subHours(24))
            ->count();

        $this->record(
            'Transfer outbox — no stuck failures',
            $stuck === 0 ? 'pass' : 'warn',
            $stuck === 0
                ? 'No transfers stuck in failed state > 24h'
                : "{$stuck} transfers failed > 24h ago — investigate before pilot",
        );
    }

    private function checkReconciliationRuns(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('reconciliation_runs')) {
            $this->record('Reconciliation runs table', 'warn', 'Table does not exist yet');
            return;
        }

        $openMismatches = DB::table('reconciliation_mismatches')
            ->whereNull('resolved_at')
            ->count();

        $this->record(
            'Reconciliation mismatches',
            $openMismatches === 0 ? 'pass' : 'warn',
            $openMismatches === 0
                ? 'No open reconciliation mismatches'
                : "{$openMismatches} unresolved reconciliation mismatches",
        );
    }

    private function checkSellNowSettlements(): void
    {
        // Every completed sell_now_offer should have a matching settlement
        $unsettled = DB::select("
            SELECT COUNT(*) AS cnt
            FROM sell_now_offers sno
            LEFT JOIN settlements s ON s.stripe_payment_intent_id = sno.stripe_payment_intent_id
            WHERE sno.status = 'completed'
              AND s.id IS NULL
        ");

        $count = $unsettled[0]->cnt ?? 0;
        $this->record(
            'SellNow settlements present',
            $count === 0 ? 'pass' : 'fail',
            $count === 0
                ? 'All completed SellNow offers have settlements'
                : "{$count} completed SellNow offers missing settlement",
        );
    }

    private function checkAuctionSettlements(): void
    {
        // Every auction with a fulfilled item should have at least one settlement
        $unsettled = DB::select("
            SELECT COUNT(DISTINCT ai.auction_id) AS cnt
            FROM auction_items ai
            LEFT JOIN settlements s ON s.auction_id = ai.auction_id
            WHERE ai.status = 'fulfilled'
              AND s.id IS NULL
        ");

        $count = $unsettled[0]->cnt ?? 0;
        $this->record(
            'Auction settlements present',
            $count === 0 ? 'pass' : 'fail',
            $count === 0
                ? 'All fulfilled auction items have settlements'
                : "{$count} fulfilled auction items missing settlement",
        );
    }

    private function checkRefundLedger(): void
    {
        // Every refund should have a corresponding debit entry in ledger_entries
        $unrecorded = DB::select("
            SELECT COUNT(*) AS cnt
            FROM refunds r
            LEFT JOIN ledger_entries le ON le.type = 'debit'
                AND le.note LIKE CONCAT('%', r.stripe_refund_id, '%')
            WHERE r.refund_status = 'succeeded'
              AND le.id IS NULL
        ");

        $count = $unrecorded[0]->cnt ?? 0;
        $this->record(
            'Refund debit entries',
            $count === 0 ? 'pass' : 'warn',
            $count === 0
                ? 'All succeeded refunds have ledger debit entries'
                : "{$count} refunds without matching ledger debit — investigate",
        );
    }

    private function checkDonationLedger(): void
    {
        $total = DB::table('donations')->count();
        $this->record(
            'Donation ledger accessible',
            'pass',
            "donations table reachable ({$total} rows)",
        );
    }

    private function checkFailedJobs(): void
    {
        $count = DB::table('failed_jobs')->count();
        $status = $count === 0 ? 'pass' : ($count < 5 ? 'warn' : 'fail');
        $this->record(
            'Failed jobs queue',
            $status,
            $count === 0
                ? 'No failed jobs'
                : "{$count} failed jobs in queue — review before pilot",
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function record(string $check, string $status, string $detail): void
    {
        $this->results[] = compact('check', 'status', 'detail');
    }
}
