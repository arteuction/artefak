<?php

declare(strict_types=1);

namespace App\Domain\Donation;

use App\Models\Donation;
use App\Models\DonorFiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * Upserts the donor's fiscal-year aggregate row for a confirmed donation.
 *
 * Idempotent: safe to call multiple times for the same donation — the running
 * totals are maintained by adding the delta each call, so callers MUST pass
 * each confirmed donation exactly once (typically from a consumer inbox worker).
 *
 * Does NOT compute tax liability or advise on taxable profit — those are the
 * donor's accountant's responsibility. This class only tracks the statutory
 * deduction ceiling per ЗКПО чл.31.
 */
final class AccumulateDonorFiscalYear
{
    public function execute(Donation $donation): DonorFiscalYear
    {
        if ($donation->donor_id === null) {
            throw new \InvalidArgumentException('Donation must have a donor_id.');
        }

        $year  = (int) $donation->created_at->format('Y');
        $basis = $donation->eligibility_basis;

        return DB::transaction(function () use ($donation, $year, $basis): DonorFiscalYear {
            $row = DonorFiscalYear::where('donor_id', $donation->donor_id)
                ->where('fiscal_year', $year)
                ->where('eligibility_basis', $basis)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return DonorFiscalYear::create([
                    'donor_id'                      => $donation->donor_id,
                    'fiscal_year'                   => $year,
                    'eligibility_basis'             => $basis,
                    'aggregate_donated_cents'       => $donation->donated_cents,
                    'aggregate_max_deductible_cents'=> $donation->max_deductible_cents,
                    'donation_count'                => 1,
                    'documentation_status'          => 'incomplete',
                ]);
            }

            $row->increment('aggregate_donated_cents',        $donation->donated_cents);
            $row->increment('aggregate_max_deductible_cents', $donation->max_deductible_cents);
            $row->increment('donation_count');

            return $row->fresh();
        });
    }
}
