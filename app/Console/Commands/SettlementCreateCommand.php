<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\Money;
use App\Domain\Settlement\RecipientLine;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use Illuminate\Console\Command;

/**
 * Used only by the concurrent integration test (Phase 1f).
 * Spawned as N parallel processes; each tries to create the same settlement.
 * Prints the resulting settlement ID to stdout.
 */
class SettlementCreateCommand extends Command
{
    protected $signature   = 'settlement:create-test {payment_intent} {event_id} {gross_cents=10000}';
    protected $description = 'Create a test settlement (Phase 1f concurrency probe)';

    public function handle(CreateSettlement $action): int
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $calc    = new SettlementCalculator();
        $gross   = Money::fromCents((int) $this->argument('gross_cents'));
        $result  = $calc->calculate($gross, $profile);

        $recipients = [
            new RecipientLine('artist', $result->artist),
            new RecipientLine('fund',   $result->fund),
            new RecipientLine('ops',    $result->ops),
        ];

        $id = $action->execute(
            $result,
            $this->argument('payment_intent'),
            $this->argument('event_id'),
            $recipients,
        );

        $this->line((string) $id);

        return self::SUCCESS;
    }
}
