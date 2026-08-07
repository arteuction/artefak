<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Splits the social fund pool among NGO recipients by integer weight.
 * Uses Money::allocate() — largest-remainder, earliest-index bias.
 * NGO sub-splits live INSIDE fundBps (per Settlement Kernel Spec).
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class FundSplit
{
    /**
     * @param  Money          $fundPool  The fund share from SettlementResult.
     * @param  array<string, int>  $weights  [ngo_id => weight]. All weights >= 0.
     * @return array<string, Money>          Same keys, same order.
     */
    public static function split(Money $fundPool, array $weights): array
    {
        if (empty($weights)) {
            throw new \InvalidArgumentException('FundSplit requires at least one NGO recipient.');
        }

        $keys  = array_keys($weights);
        $parts = $fundPool->allocate(array_values($weights));

        return array_combine($keys, $parts);
    }
}
