<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Pure domain calculator — no DB, no Laravel, no Stripe.
 *
 * P0 formula (единствена):
 *   artist = floor(gross_cents × artistBps / 10000)
 *   fund   = floor(gross_cents × fundBps   / 10000)
 *   ops    = gross_cents − artist − fund   ← поема rounding остатъка
 *
 * Резултатът е immutable SettlementResult с frozen profile snapshot.
 */
final class SettlementCalculator
{
    /** Bulgaria adopted EUR on 2026-01-01; all ARTeuCtion settlements are in EUR. */
    private const CURRENCY = 'EUR';

    public function calculate(Money $gross, SplitProfile $profile): SettlementResult
    {
        if ($gross->currency !== self::CURRENCY) {
            throw new \InvalidArgumentException(
                "Only EUR is accepted for settlement, got: {$gross->currency}"
            );
        }

        if ($gross->isNegative()) {
            throw new \InvalidArgumentException(
                "Gross amount cannot be negative, got: {$gross->cents} cents"
            );
        }

        $grossCents  = $gross->cents;
        $artistCents = intdiv($grossCents * $profile->artistBps(), 10000);
        $fundCents   = intdiv($grossCents * $profile->fundBps(), 10000);
        $opsCents    = $grossCents - $artistCents - $fundCents;

        return new SettlementResult(
            gross:          $gross,
            artist:         Money::fromCents($artistCents, self::CURRENCY),
            fund:           Money::fromCents($fundCents,   self::CURRENCY),
            ops:            Money::fromCents($opsCents,    self::CURRENCY),
            profileKey:     $profile->key(),
            profileVersion: $profile->version(),
            artistBps:      $profile->artistBps(),
            fundBps:        $profile->fundBps(),
            opsBps:         $profile->operationsBps(),
        );
    }
}
