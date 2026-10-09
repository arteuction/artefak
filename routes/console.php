<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auction:close-expired-lots')->everyMinute()->withoutOverlapping();

// Domain event outbox — drain every minute, reclaiming stale leases first.
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();

// Nightly reconciliation at 02:00 — after Stripe settles overnight payouts.
// On mismatch the command exits non-zero and logs to Log::error('reconciliation.mismatch').
Schedule::command('reconciliation:nightly')->dailyAt('02:00')->withoutOverlapping();

// Operational health check — broadcasts admin alerts for failed transfers,
// stuck domain events, etc. Runs every 5 minutes.
Schedule::command('arteuction:check-operational-alerts')->everyFiveMinutes()->withoutOverlapping();

// Proactively mark bids whose Stripe authorization has expired (7-day window).
// Fires an admin alert for each expired winning bid requiring operator action.
Schedule::command('auction:expire-bid-authorizations')->hourly()->withoutOverlapping();
