<?php

declare(strict_types=1);

namespace App\Checks;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when transfer_outbox has too many failed rows waiting for manual review.
 */
final class OutboxBacklogCheck extends Check
{
    private int $warnThreshold = 1;
    private int $failThreshold = 10;

    public function warnWhenBacklogExceeds(int $count): static
    {
        $this->warnThreshold = $count;
        return $this;
    }

    public function failWhenBacklogExceeds(int $count): static
    {
        $this->failThreshold = $count;
        return $this;
    }

    public function run(): Result
    {
        $failed = DB::table('transfer_outbox')
            ->where('status', 'failed')
            ->count();

        if ($failed >= $this->failThreshold) {
            return Result::make()
                ->failed("{$failed} failed transfer(s) in outbox — manual review required.");
        }

        if ($failed >= $this->warnThreshold) {
            return Result::make()
                ->warning("{$failed} failed transfer(s) in outbox.");
        }

        return Result::make()->ok();
    }
}
