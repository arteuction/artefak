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
 *
 * Designed to run every 5 minutes via the scheduler.
 * Fires AdminOperationalAlert broadcasts for each anomaly found.
 */
final class CheckOperationalAlerts extends Command
{
    protected $signature   = 'arteuction:check-operational-alerts';
    protected $description = 'Broadcast admin alerts for failed transfers and stuck domain events';

    public function handle(OperationalAlertService $alerts): int
    {
        $this->checkFailedTransfers($alerts);
        $this->checkStuckDomainEvents($alerts);
        $this->checkFailedDomainEvents($alerts);

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
}
