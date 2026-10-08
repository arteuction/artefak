<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Financial\RunReconciliation;
use App\Models\ReconciliationRun;
use Illuminate\Console\Command;
use Stripe\StripeClient;

/**
 * Runs nightly financial reconciliation for yesterday's activity.
 *
 * Fetches Stripe balance transactions for the period, passes the totals to
 * RunReconciliation (which is Stripe-agnostic and fully testable), and emits
 * a log warning when a mismatch is detected so the operations on-call knows.
 *
 * Schedule: nightly at 02:00 (after Stripe settles overnight payouts).
 * Usage: php artisan reconciliation:nightly [--date=YYYY-MM-DD]
 *
 * The --date flag is for manual backfills; omit it for the nightly default.
 */
final class RunNightlyReconciliationCommand extends Command
{
    protected $signature   = 'reconciliation:nightly {--date= : Override the reconciliation date (YYYY-MM-DD, default: yesterday)}';
    protected $description = 'Run financial reconciliation for the previous day and log any mismatch.';

    public function handle(StripeClient $stripe): int
    {
        $date = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))
            : now()->subDay();

        $periodStart = $date->copy()->startOfDay();
        $periodEnd   = $date->copy()->endOfDay();

        $this->info("Reconciliation: {$periodStart->toDateString()} → {$periodEnd->toDateString()}");

        // ── Fetch from Stripe ────────────────────────────────────────────
        // Balance transactions: sum all 'charge' type entries (received).
        // Transfer transactions: sum all 'transfer' type entries (sent out).
        // The domain action is Stripe-agnostic; we pass totals only.
        try {
            $stripeReceivedCents    = $this->sumStripeBalanceType($stripe, $periodStart, $periodEnd, 'charge');
            $stripeTransferredCents = $this->sumStripeBalanceType($stripe, $periodStart, $periodEnd, 'transfer');
        } catch (\Throwable $e) {
            $this->error("Stripe fetch failed: {$e->getMessage()}");
            ReconciliationRun::create([
                'period_start' => $periodStart->toDateString(),
                'period_end'   => $periodEnd->toDateString(),
                'status'       => 'error',
            ])->update(['status' => 'error']);
            return Command::FAILURE;
        }

        $run = (new RunReconciliation())->execute(
            periodStart:            $periodStart,
            periodEnd:              $periodEnd,
            stripeReceivedCents:    $stripeReceivedCents,
            stripeTransferredCents: $stripeTransferredCents,
        );

        if ($run->isMismatched()) {
            $this->error(
                "RECONCILIATION MISMATCH for {$periodStart->toDateString()}: " .
                "delta_received={$run->delta_received_cents} delta_transferred={$run->delta_transferred_cents}"
            );
            \Illuminate\Support\Facades\Log::error('reconciliation.mismatch', [
                'run_id'              => $run->id,
                'period'              => $periodStart->toDateString(),
                'delta_received'      => $run->delta_received_cents,
                'delta_transferred'   => $run->delta_transferred_cents,
                'internal_gross'      => $run->internal_gross_cents,
                'stripe_received'     => $run->stripe_received_cents,
            ]);
            return Command::FAILURE;
        }

        $this->info(
            "Matched: {$run->internal_settlement_count} settlements, " .
            "{$run->internal_gross_cents} gross cents."
        );
        return Command::SUCCESS;
    }

    private function sumStripeBalanceType(
        StripeClient $stripe,
        \Carbon\Carbon $start,
        \Carbon\Carbon $end,
        string $type,
    ): int {
        $total  = 0;
        $params = [
            'type'           => $type,
            'created'        => ['gte' => $start->timestamp, 'lte' => $end->timestamp],
            'limit'          => 100,
        ];

        do {
            $page  = $stripe->balanceTransactions->all($params);
            foreach ($page->data as $txn) {
                $total += $txn->amount;
            }
            $params['starting_after'] = $page->data ? end($page->data)->id : null;
        } while ($page->has_more);

        return $total;
    }
}
