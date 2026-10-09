<?php

declare(strict_types=1);

namespace App\Domain\Donation;

/**
 * Result of applying ЗКПО чл.31 profit-based ceilings to a donor's fiscal year.
 *
 * profitCeilingCents = positiveProfitCents × deductionBps / 10 000
 * recognizedCents    = min(totalDonatedCents, profitCeilingCents)
 *
 * When positiveProfitCents < 0, both ceilings and recognized amount are zero.
 */
final readonly class AnnualCeilingResult
{
    public function __construct(
        public EligibilityBasis $basis,
        public int              $positiveProfitCents,
        public int              $totalDonatedCents,
        public int              $profitCeilingCents,
        public int              $recognizedCents,
    ) {}

    public function fullyRecognized(): bool
    {
        return $this->recognizedCents >= $this->totalDonatedCents;
    }

    public function excessCents(): int
    {
        return max(0, $this->totalDonatedCents - $this->profitCeilingCents);
    }
}
