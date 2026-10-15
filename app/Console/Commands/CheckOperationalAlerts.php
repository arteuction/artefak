<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic check for operational anomalies:
 *   - Failed transfer_outbox rows (Stripe transfer not dispatched)
 *   - Stuck domain_events (pending/processing for > 10 minutes)
 *   - Failed domain_events
 *   - Failed queue jobs (failed_jobs table)
 *   - Stale queue jobs (jobs table — indicates worker is down)
 *   - Abandoned outbox leases (processing rows that missed their 5-min LEASE_MINUTES window)
 *   - Abandoned reversal order leases
 *
 * Designed to run every 5 minutes via the scheduler.
 * Fires AdminOperationalAlert broadcasts for each anomaly found.
 */
final class CheckOperationalAlerts extends Command
{
    // A queue job that has been reserved but not acked for longer than
    // retry_after (90s) is evidence the worker is hung or dead.
    private const QUEUE_STALE_SECONDS = 300; // 5 min = well past retry_after=90s

    // Must match DispatchStripeTransfer::LEASE_MINUTES and DispatchStripeReversal::LEASE_MINUTES
    private const OUTBOX_LEASE_MINUTES = 5;

    protected $signature   = 'arteuction:check-operational-alerts';
    protected $description = 'Broadcast admin alerts for failed transfers, stuck domain events, and queue health';

    public function handle(OperationalAlertService $alerts): int
    {
        $this->checkFailedTransfers($alerts);
        $this->checkStuckDomainEvents($alerts);
        $this->checkFailedDomainEvents($alerts);
        $this->checkFailedQueueJobs($alerts);
        $this->checkStaleQueueJobs($alerts);
        $this->checkAbandonedOutboxLeases($alerts);
        $this->checkAbandonedReversalLeases($alerts);

        return self::SUCCESS;
    }

    private function checkFailedTransfers(OperationalAlertService $alerts): void
    {
        $count = DB::table('transfer_outbox')
            ->where('status', 'failed')
            ->count();

        if ($count > 0) {
            $alerts->alert(
                type:    'transfer_failed',
                message: "{$count} transfer(s) in transfer_outbox have status=failed and require manual review.",
                context: ['count' => $count],
            );
        }
    }

    private function checkStuckDomainEvents(OperationalAlertService $alerts): void
    {
        $cutoff = now()->subMinutes(10);

        $count = DB::table('domain_events')
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', $cutoff)
            ->count();

        if ($count > 0) {
            $alerts->alert(
                type:    'domain_event_stuck',
                message: "{$count} domain event(s) stuck in pending/processing for over 10 minutes.",
                context: ['count' => $count, 'cutoff' => $cutoff->toIso8601String()],
            );
        }
    }

    private function checkFailedDomainEvents(OperationalAlertService $alerts): void
    {
        $count = DB::table('domain_events')
            ->where('status', 'failed')
            ->count();

        if ($count > 0) {
            $alerts->alert(
                type:    'domain_event_failed',
                message: "{$count} domain event(s) have status=failed and need investigation.",
                context: ['count' => $count],
            );
        }
    }

    private function checkFailedQueueJobs(OperationalAlertService $alerts): void
    {
        // Count by queue so the alert message is actionable
        $rows = DB::table('failed_jobs')
            ->selectRaw('queue, COUNT(*) as cnt')
            ->groupBy('queue')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $total   = $rows->sum('cnt');
        $byQueue = $rows->pluck('cnt', 'queue')->toArray();

        $alerts->alert(
            type:    'queue_jobs_failed',
            message: "{$total} job(s) in failed_jobs — review and retry or discard: "
                . collect($byQueue)->map(fn ($c, $q) => "{$q}:{$c}")->implode(', '),
            context: ['total' => $total, 'by_queue' => $byQueue],
        );
    }

    private function checkStaleQueueJobs(OperationalAlertService $alerts): void
    {
        // reserved_at is a Unix timestamp set when a worker picks up the job.
        // If it remains set beyond QUEUE_STALE_SECONDS the worker likely crashed.
        $staleCutoff = now()->subSeconds(self::QUEUE_STALE_SECONDS)->timestamp;

        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as cnt')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', $staleCutoff)
            ->groupBy('queue')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $total   = $rows->sum('cnt');
        $byQueue = $rows->pluck('cnt', 'queue')->toArray();

        $alerts->alert(
            type:    'queue_jobs_stale_reserved',
            message: "{$total} queue job(s) reserved for over " . self::QUEUE_STALE_SECONDS
                . "s — worker may be hung or dead: "
                . collect($byQueue)->map(fn ($c, $q) => "{$q}:{$c}")->implode(', '),
            context: ['total' => $total, 'by_queue' => $byQueue, 'stale_seconds' => self::QUEUE_STALE_SECONDS],
        );
    }

    private function checkAbandonedOutboxLeases(OperationalAlertService $alerts): void
    {
        $cutoff = now()->subMinutes(self::OUTBOX_LEASE_MINUTES);

        $count = DB::table('transfer_outbox')
            ->where('status', 'processing')
            ->where('processing_started_at', '<', $cutoff)
            ->count();

        if ($count > 0) {
            $alerts->alert(
                type:    'outbox_lease_abandoned',
                message: "{$count} transfer_outbox row(s) stuck in processing for over "
                    . self::OUTBOX_LEASE_MINUTES . " minutes — abandoned lease not yet reclaimed.",
                context: ['count' => $count, 'lease_minutes' => self::OUTBOX_LEASE_MINUTES],
            );
        }
    }

    private function checkAbandonedReversalLeases(OperationalAlertService $alerts): void
    {
        $cutoff = now()->subMinutes(self::OUTBOX_LEASE_MINUTES);

        $count = DB::table('transfer_reversal_orders')
            ->where('status', 'processing')
            ->where('processing_started_at', '<', $cutoff)
            ->count();

        if ($count > 0) {
            $alerts->alert(
                type:    'reversal_lease_abandoned',
                message: "{$count} transfer_reversal_order(s) stuck in processing for over "
                    . self::OUTBOX_LEASE_MINUTES . " minutes — abandoned lease not yet reclaimed.",
                context: ['count' => $count, 'lease_minutes' => self::OUTBOX_LEASE_MINUTES],
            );
        }
    }
}
