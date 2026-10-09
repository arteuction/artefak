<?php

declare(strict_types=1);

namespace App\Checks;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when domain_events has rows stuck in failed status.
 * Failed domain events may represent unprocessed financial or state transitions.
 */
final class FailedDomainEventsCheck extends Check
{
    private int $failThreshold = 5;

    public function failWhenCountExceeds(int $count): static
    {
        $this->failThreshold = $count;
        return $this;
    }

    public function run(): Result
    {
        $failed = DB::table('domain_events')
            ->where('status', 'failed')
            ->count();

        $stuck = DB::table('domain_events')
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', now()->subMinutes(15))
            ->count();

        if ($failed >= $this->failThreshold || $stuck > 0) {
            return Result::make()
                ->failed(
                    "{$failed} failed domain event(s); {$stuck} stuck event(s) (>15 min)."
                );
        }

        if ($failed > 0) {
            return Result::make()
                ->warning("{$failed} failed domain event(s) pending review.");
        }

        return Result::make()->ok();
    }
}
