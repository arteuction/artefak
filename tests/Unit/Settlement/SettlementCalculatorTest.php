<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Domain\Settlement\Money;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SettlementResult;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — SettlementCalculator (pure domain, no DB/Laravel/Stripe).
 * RED by design until SettlementCalculator is implemented.
 *
 * Rule: ops absorbs the rounding residual.
 *   artist = floor(gross × 4500 / 10000)
 *   fund   = floor(gross × 4500 / 10000)
 *   ops    = gross − artist − fund
 */
final class SettlementCalculatorTest extends TestCase
{
    private SettlementCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SettlementCalculator();
    }

    /** €0.01 — ops absorbs the only cent */
    public function test_one_cent_goes_entirely_to_ops(): void
    {
        $r = $this->calc->calculate(Money::fromCents(1));
        $this->assertSame(0, $r->artist->cents);
        $this->assertSame(0, $r->fund->cents);
        $this->assertSame(1, $r->ops->cents);
        $this->assertReconciles($r);
    }

    /** €9.99 */
    public function test_nine_ninety_nine(): void
    {
        $r = $this->calc->calculate(Money::fromCents(999));
        $this->assertSame(449, $r->artist->cents);
        $this->assertSame(449, $r->fund->cents);
        $this->assertSame(101, $r->ops->cents);
        $this->assertReconciles($r);
    }

    /** €99.99 */
    public function test_ninety_nine_ninety_nine(): void
    {
        $r = $this->calc->calculate(Money::fromCents(9999));
        $this->assertSame(4499, $r->artist->cents);
        $this->assertSame(4499, $r->fund->cents);
        $this->assertSame(1001, $r->ops->cents);
        $this->assertReconciles($r);
    }

    /** €100.00 — clean split, no residual */
    public function test_hundred_euros_clean_split(): void
    {
        $r = $this->calc->calculate(Money::fromCents(10000));
        $this->assertSame(4500, $r->artist->cents);
        $this->assertSame(4500, $r->fund->cents);
        $this->assertSame(1000, $r->ops->cents);
        $this->assertReconciles($r);
    }

    /** €1000.00 */
    public function test_thousand_euros(): void
    {
        $r = $this->calc->calculate(Money::fromCents(100000));
        $this->assertSame(45000, $r->artist->cents);
        $this->assertSame(45000, $r->fund->cents);
        $this->assertSame(10000, $r->ops->cents);
        $this->assertReconciles($r);
    }

    /** Frozen profile snapshot on the result */
    public function test_result_carries_frozen_profile_snapshot(): void
    {
        $r = $this->calc->calculate(Money::fromCents(10000));
        $this->assertSame('social_pilot_45_45_10', $r->profileKey);
        $this->assertSame(1,    $r->profileVersion);
        $this->assertSame(4500, $r->artistBps);
        $this->assertSame(4500, $r->fundBps);
        $this->assertSame(1000, $r->opsBps);
    }

    /** Currency is preserved on all parts */
    public function test_currency_is_eur_on_all_parts(): void
    {
        $r = $this->calc->calculate(Money::fromCents(10000, 'EUR'));
        $this->assertSame('EUR', $r->artist->currency);
        $this->assertSame('EUR', $r->fund->currency);
        $this->assertSame('EUR', $r->ops->currency);
    }

    /** Non-EUR must throw */
    public function test_non_eur_gross_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate(Money::fromCents(10000, 'BGN'));
    }

    /** Negative gross must throw */
    public function test_negative_gross_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate(Money::fromCents(-1));
    }

    private function assertReconciles(SettlementResult $r): void
    {
        $sum = $r->artist->cents + $r->fund->cents + $r->ops->cents;
        $this->assertSame(
            $r->gross->cents,
            $sum,
            "Reconciliation failed: artist+fund+ops={$sum} != gross={$r->gross->cents}"
        );
    }
}
