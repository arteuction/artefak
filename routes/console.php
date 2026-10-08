<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auction:close-expired-lots')->everyMinute()->withoutOverlapping();

// Nightly reconciliation at 02:00 — after Stripe settles overnight payouts.
// On mismatch the command exits non-zero and logs to Log::error('reconciliation.mismatch').
Schedule::command('reconciliation:nightly')->dailyAt('02:00')->withoutOverlapping();
