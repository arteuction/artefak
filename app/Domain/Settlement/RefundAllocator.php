<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Allocates a refund amount across the original settlement lines.
 *
 * Uses the frozen original amount_cents as weights — no recalculation of
 * the 45/45/10 split.  Largest-remainder via Money::allocate() ensures
 * the parts sum exactly to the refund amount.
 *
 * Works for both full and partial refunds.
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class RefundAllocator
{
    /**
     * @param  Money  $refundAmount   Amount to distribute (≤ gross of settlement).
     * @param  array  $lines          Each element: ['id' => int, 'amount_cents' => int, 'currency' => string, 'recipient_type' => string]
     * @return array<int, Money>      Keyed by settlement_line_id.
     */
    public static function allocate(Money $refundAmount, array $lines): array
    {
        if (empty($lines)) {
            throw new \InvalidArgumentException('RefundAllocator requires at least one settlement line.');
        }

        $weights = array_map(fn (array $l): int => $l['amount_cents'], $lines);
        $parts   = $refundAmount->allocate($weights);

        $result = [];
        foreach ($lines as $i => $line) {
            $result[$line['id']] = $parts[$i];
        }

        return $result;
    }
}
