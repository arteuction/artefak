<?php

declare(strict_types=1);

namespace App\Domain\Donation;

/**
 * Pure domain calculator — no DB or Stripe dependencies.
 *
 * Records the statutory ceiling RATE for a donation under ЗКПО чл.31.
 *
 * IMPORTANT: the percentages in Art.31 (10%, 15%, 50%) are limits expressed
 * as a fraction of the donor's POSITIVE ACCOUNTING PROFIT for the fiscal year —
 * NOT a fraction of the donation amount itself.
 *
 * For example, a company with 100 000 BGN profit that donates 1 000 BGN under
 * Art.31 al.1 (10% limit) has a ceiling of 10 000 BGN. The 1 000 BGN donation
 * falls entirely within the ceiling — max_deductible_cents at donation time = 1 000.
 *
 * The actual recognizable deduction is calculated in DonorFiscalYearAssessment
 * once the donor's annual accounting profit is known. This class only validates
 * the donation and snapshots the applicable statutory rate.
 *
 * max_deductible_cents stored on the Donation row is the AMOUNT DONATED (since
 * that is the upper bound on what can ever be claimed), NOT donation × bps/10000.
 * The true ceiling against profit is evaluated in the annual assessment.
 */
final class DonationCalculator
{
    /**
     * @return DonationResult   donated_cents, deduction_bps, max_deductible_cents
     *
     * max_deductible_cents = min(donated_cents, profitCents * bps / 10000) but since
     * profit is not yet known at donation time, we store donated_cents as the provisional
     * maximum. The annual assessment applies the profit-based ceiling.
     */
    public function calculate(int $donatedCents, EligibilityBasis $basis): DonationResult
    {
        if ($donatedCents <= 0) {
            throw new \InvalidArgumentException('Donated amount must be positive.');
        }

        $bps = $basis->deductionBps();

        // Provisional upper bound: the entire donation amount.
        // The profit-based ceiling is applied during annual assessment.
        $provisionalMaxDeductible = $donatedCents;

        return new DonationResult(
            donatedCents:       $donatedCents,
            eligibilityBasis:   $basis,
            deductionBps:       $bps,
            maxDeductibleCents: $provisionalMaxDeductible,
        );
    }

    /**
     * Compute the actual recognized deduction ceiling once accounting profit is known.
     *
     * This is the profit-based statutory ceiling per EligibilityBasis.
     * The final recognized amount is min(totalDonatedCents, profitCeilingCents).
     *
     * @param  int  $positiveProfitCents  Donor's positive accounting profit for the fiscal year.
     * @param  int  $totalDonatedCents   Total donated under this basis in the fiscal year.
     */
    public function assessAnnualCeiling(
        int            $positiveProfitCents,
        int            $totalDonatedCents,
        EligibilityBasis $basis,
    ): AnnualCeilingResult {
        if ($positiveProfitCents < 0) {
            // No positive accounting profit — zero deductible under Art.31.
            return new AnnualCeilingResult(
                basis:                 $basis,
                positiveProfitCents:   $positiveProfitCents,
                totalDonatedCents:     $totalDonatedCents,
                profitCeilingCents:    0,
                recognizedCents:       0,
            );
        }

        $profitCeiling  = (int) floor($positiveProfitCents * $basis->deductionBps() / 10000);
        $recognized     = min($totalDonatedCents, $profitCeiling);

        return new AnnualCeilingResult(
            basis:               $basis,
            positiveProfitCents: $positiveProfitCents,
            totalDonatedCents:   $totalDonatedCents,
            profitCeilingCents:  $profitCeiling,
            recognizedCents:     $recognized,
        );
    }
}
