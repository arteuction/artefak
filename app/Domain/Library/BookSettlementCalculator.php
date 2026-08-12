<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Domain\Settlement\Money;
use App\Domain\Settlement\SplitProfile;

/**
 * Pure calculator for digital library book sales.
 *
 * Accepts split_base_cents (gross - tax - fee) and a profile key.
 * Returns an immutable BookSettlementResult with frozen bps snapshot.
 *
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class BookSettlementCalculator
{
    /**
     * @param  int    $splitBaseCents  gross_cents - tax_cents - fee_cents
     * @param  string $profileKey      e.g. 'library_80_10_10'
     * @param  string $currency        ISO 4217
     */
    public function calculate(int $splitBaseCents, string $profileKey, string $currency = 'EUR'): BookSettlementResult
    {
        if ($splitBaseCents < 0) {
            throw new \InvalidArgumentException(
                "split_base_cents cannot be negative, got: {$splitBaseCents}"
            );
        }

        $profile = SplitProfile::fromKey($profileKey);

        $authorCents = intdiv($splitBaseCents * $profile->artistBps(), 10000);
        $fundCents   = intdiv($splitBaseCents * $profile->fundBps(),   10000);
        // ops absorbs rounding remainder — sum is always exactly split_base_cents
        $opsCents    = $splitBaseCents - $authorCents - $fundCents;

        return new BookSettlementResult(
            splitBase:      Money::fromCents($splitBaseCents, $currency),
            author:         Money::fromCents($authorCents,    $currency),
            fund:           Money::fromCents($fundCents,      $currency),
            ops:            Money::fromCents($opsCents,       $currency),
            profileKey:     $profile->key(),
            profileVersion: $profile->version(),
            authorBps:      $profile->artistBps(),
            fundBps:        $profile->fundBps(),
            opsBps:         $profile->operationsBps(),
        );
    }
}
