<?php

declare(strict_types=1);

namespace App\Domain\Donation;

use App\Models\DonorFiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * Applies the ЗКПО чл.31 profit-based statutory ceiling once the donor's
 * positive accounting profit for the fiscal year is known.
 *
 * This is a separate action from AccumulateDonorFiscalYear because profit is
 * declared after the fiscal year ends, while donations accumulate throughout.
 *
 * Idempotent: calling again with the same profit re-computes and updates the row.
 * Status transitions: pending → assessed (on first call), assessed → assessed (re-run).
 * reviewed status requires manual operator action and is not set here.
 */
final class AssessDonorFiscalYear
{
    public function __construct(
        private readonly DonationCalculator $calculator = new DonationCalculator(),
    ) {}

    /**
     * @param  int  $positiveProfitCents  Declared accounting profit (may be 0 for loss year).
     */
    public function execute(DonorFiscalYear $row, int $positiveProfitCents): AnnualCeilingResult
    {
        if ($row->assessment_status === 'reviewed') {
            throw new \LogicException(
                "DonorFiscalYear #{$row->id} has been reviewed and cannot be re-assessed automatically."
            );
        }

        $basis  = EligibilityBasis::from($row->eligibility_basis);
        $result = $this->calculator->assessAnnualCeiling(
            positiveProfitCents: $positiveProfitCents,
            totalDonatedCents:   (int) $row->aggregate_donated_cents,
            basis:               $basis,
        );

        DB::table('donor_fiscal_years')
            ->where('id', $row->id)
            ->update([
                'positive_profit_cents'      => $positiveProfitCents,
                'profit_ceiling_cents'        => $result->profitCeilingCents,
                'recognized_deductible_cents' => $result->recognizedCents,
                'assessment_status'           => 'assessed',
                'assessed_at'                 => now(),
                'updated_at'                  => now(),
            ]);

        return $result;
    }
}
