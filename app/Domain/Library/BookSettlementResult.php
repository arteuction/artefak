<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Domain\Settlement\Money;

/**
 * Immutable result of BookSettlementCalculator.
 * All values are derived from split_base_cents (gross - tax - fee).
 */
final class BookSettlementResult
{
    public function __construct(
        public readonly Money  $splitBase,
        public readonly Money  $author,
        public readonly Money  $fund,
        public readonly Money  $ops,
        public readonly string $profileKey,
        public readonly int    $profileVersion,
        public readonly int    $authorBps,
        public readonly int    $fundBps,
        public readonly int    $opsBps,
    ) {
        assert($author->cents + $fund->cents + $ops->cents === $splitBase->cents,
            'BookSettlementResult: parts must sum to split_base_cents');
    }
}
