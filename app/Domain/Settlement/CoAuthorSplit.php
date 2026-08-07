<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Splits the artist pool among co-authors by integer weight.
 * Uses Money::allocate() — largest-remainder, earliest-index bias.
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class CoAuthorSplit
{
    /**
     * @param  Money          $artistPool  The artist share from SettlementResult.
     * @param  array<string, int>  $weights  [author_id => weight]. All weights >= 0.
     * @return array<string, Money>          Same keys, same order.
     */
    public static function split(Money $artistPool, array $weights): array
    {
        if (empty($weights)) {
            throw new \InvalidArgumentException('CoAuthorSplit requires at least one author.');
        }

        $keys   = array_keys($weights);
        $parts  = $artistPool->allocate(array_values($weights));

        return array_combine($keys, $parts);
    }
}
