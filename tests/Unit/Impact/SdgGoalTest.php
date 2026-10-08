<?php

declare(strict_types=1);

namespace Tests\Unit\Impact;

use App\Domain\Impact\ImpactMetric;
use App\Domain\Impact\SdgGoal;
use PHPUnit\Framework\TestCase;

class SdgGoalTest extends TestCase
{
    public function test_all_17_sdgs_exist(): void
    {
        $this->assertCount(17, SdgGoal::cases());
        for ($i = 1; $i <= 17; $i++) {
            $goal = SdgGoal::from($i);
            $this->assertEquals($i, $goal->value);
        }
    }

    public function test_labels_are_non_empty(): void
    {
        foreach (SdgGoal::cases() as $goal) {
            $this->assertStringStartsWith("SDG {$goal->value}", $goal->label());
        }
    }

    public function test_impact_metric_monetary_flag(): void
    {
        $this->assertTrue(ImpactMetric::SaleAmountEur->isMonetary());
        $this->assertTrue(ImpactMetric::DonationAmountEur->isMonetary());
        $this->assertFalse(ImpactMetric::AudienceReach->isMonetary());
        $this->assertFalse(ImpactMetric::ArtworksSold->isMonetary());
        $this->assertFalse(ImpactMetric::DonorsCount->isMonetary());
    }

    public function test_all_metrics_have_labels(): void
    {
        foreach (ImpactMetric::cases() as $metric) {
            $this->assertNotEmpty($metric->label());
        }
    }
}
