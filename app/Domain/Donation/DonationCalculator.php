<?php

declare(strict_types=1);

namespace App\Domain\Donation;

/**
 * Pure domain calculator — no DB or Stripe dependencies.
 *
 * Computes the maximum tax-deductible amount for a donation under ЗКПО чл.31.
 * The actual deduction is capped at the donor's taxable profit by the donor's
 * own accountant; this class only computes the statutory ceiling per ZKPO.
 */
final class DonationCalculator
{
    /**
     * @return DonationResult   donated_cents, deduction_bps, max_deductible_cents
     */
    public function calculate(int $donatedCents, EligibilityBasis $basis): DonationResult
    {
        if ($donatedCents <= 0) {
            throw new \InvalidArgumentException('Donated amount must be positive.');
        }

        $bps               = $basis->deductionBps();
        $maxDeductible     = (int) round($donatedCents * $bps / 10000);

        return new DonationResult(
            donatedCents:       $donatedCents,
            eligibilityBasis:   $basis,
            deductionBps:       $bps,
            maxDeductibleCents: $maxDeductible,
        );
    }
}
