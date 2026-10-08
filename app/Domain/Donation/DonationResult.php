<?php

declare(strict_types=1);

namespace App\Domain\Donation;

final readonly class DonationResult
{
    public function __construct(
        public int             $donatedCents,
        public EligibilityBasis $eligibilityBasis,
        public int             $deductionBps,
        public int             $maxDeductibleCents,
    ) {}

    /** Human-readable deduction rate, e.g. "10.00%" */
    public function deductionPercent(): string
    {
        return number_format($this->deductionBps / 100, 2) . '%';
    }
}
