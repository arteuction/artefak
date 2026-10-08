<?php

declare(strict_types=1);

namespace Tests\Unit\Donation;

use App\Domain\Donation\DonationCalculator;
use App\Domain\Donation\EligibilityBasis;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DonationCalculatorTest extends TestCase
{
    private DonationCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new DonationCalculator();
    }

    public function test_art31_1_gives_10_percent(): void
    {
        $result = $this->calc->calculate(10000, EligibilityBasis::ZKPO_ART31_1);

        $this->assertEquals(10000, $result->donatedCents);
        $this->assertEquals(1000, $result->deductionBps);
        $this->assertEquals(1000, $result->maxDeductibleCents);
        $this->assertEquals('10.00%', $result->deductionPercent());
    }

    public function test_patronage_gives_15_percent(): void
    {
        $result = $this->calc->calculate(10000, EligibilityBasis::ZKPO_ART31_3_PATRONAGE);

        $this->assertEquals(1500, $result->deductionBps);
        $this->assertEquals(1500, $result->maxDeductibleCents);
        $this->assertEquals('15.00%', $result->deductionPercent());
    }

    public function test_nhi_child_treatment_gives_50_percent(): void
    {
        $result = $this->calc->calculate(10000, EligibilityBasis::ZKPO_ART31_2_NHI_CHILD_TREATMENT);

        $this->assertEquals(5000, $result->deductionBps);
        $this->assertEquals(5000, $result->maxDeductibleCents);
        $this->assertEquals('50.00%', $result->deductionPercent());
    }

    public function test_assisted_reproduction_gives_50_percent(): void
    {
        $result = $this->calc->calculate(10000, EligibilityBasis::ZKPO_ART31_2_ASSISTED_REPRODUCTION);

        $this->assertEquals(5000, $result->deductionBps);
        $this->assertEquals(5000, $result->maxDeductibleCents);
    }

    public function test_deductible_rounds_correctly(): void
    {
        // 333 cents * 10% = 33.3 → rounds to 33
        $result = $this->calc->calculate(333, EligibilityBasis::ZKPO_ART31_1);
        $this->assertEquals(33, $result->maxDeductibleCents);
    }

    public function test_zero_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(0, EligibilityBasis::ZKPO_ART31_1);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(-100, EligibilityBasis::ZKPO_ART31_1);
    }

    public function test_all_basis_have_correct_bps(): void
    {
        $this->assertEquals(1000, EligibilityBasis::ZKPO_ART31_1->deductionBps());
        $this->assertEquals(1500, EligibilityBasis::ZKPO_ART31_3_PATRONAGE->deductionBps());
        $this->assertEquals(5000, EligibilityBasis::ZKPO_ART31_2_NHI_CHILD_TREATMENT->deductionBps());
        $this->assertEquals(5000, EligibilityBasis::ZKPO_ART31_2_ASSISTED_REPRODUCTION->deductionBps());
    }

    public function test_labels_are_non_empty(): void
    {
        foreach (EligibilityBasis::cases() as $basis) {
            $this->assertNotEmpty($basis->label());
        }
    }
}
