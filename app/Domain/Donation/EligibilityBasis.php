<?php

declare(strict_types=1);

namespace App\Domain\Donation;

/**
 * Maps each ZKPO eligibility basis to its maximum deduction rate in basis points.
 *
 * Source: ЗКПО чл. 31 (effective 2024).
 * 10 000 bps = 100 %; so 1000 bps = 10 %, 1500 bps = 15 %, 5000 bps = 50 %.
 */
enum EligibilityBasis: string
{
    case ZKPO_ART31_1                      = 'ZKPO_ART31_1';
    case ZKPO_ART31_3_PATRONAGE            = 'ZKPO_ART31_3_PATRONAGE';
    case ZKPO_ART31_2_NHI_CHILD_TREATMENT  = 'ZKPO_ART31_2_NHI_CHILD_TREATMENT';
    case ZKPO_ART31_2_ASSISTED_REPRODUCTION = 'ZKPO_ART31_2_ASSISTED_REPRODUCTION';

    /** Maximum deduction rate in basis points (of taxable profit, not donated amount). */
    public function deductionBps(): int
    {
        return match ($this) {
            self::ZKPO_ART31_1                      => 1000,  // 10%
            self::ZKPO_ART31_3_PATRONAGE            => 1500,  // 15%
            self::ZKPO_ART31_2_NHI_CHILD_TREATMENT  => 5000,  // 50%
            self::ZKPO_ART31_2_ASSISTED_REPRODUCTION => 5000, // 50%
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ZKPO_ART31_1                      => 'ЗКПО чл.31, ал.1 — 10%',
            self::ZKPO_ART31_3_PATRONAGE            => 'ЗКПО чл.31, ал.3 (меценатство) — 15%',
            self::ZKPO_ART31_2_NHI_CHILD_TREATMENT  => 'ЗКПО чл.31, ал.2 (НЗОК детско лечение) — 50%',
            self::ZKPO_ART31_2_ASSISTED_REPRODUCTION => 'ЗКПО чл.31, ал.2 (НЗОК АРТ) — 50%',
        };
    }
}
