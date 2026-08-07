<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

/**
 * Immutable result of a SettlementCalculator::calculate() call.
 * Carries the gross, three parts, and the frozen profile snapshot.
 * MUST NOT depend on Laravel, Eloquent or Stripe.
 */
final class SettlementResult
{
    public function __construct(
        public readonly Money  $gross,
        public readonly Money  $artist,
        public readonly Money  $fund,
        public readonly Money  $ops,
        public readonly string $profileKey,
        public readonly int    $profileVersion,
        public readonly int    $artistBps,
        public readonly int    $fundBps,
        public readonly int    $opsBps,
    ) {}
}
