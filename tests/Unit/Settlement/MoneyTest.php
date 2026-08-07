<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Domain\Settlement\Money;
use PHPUnit\Framework\TestCase;

/**
 * Pure domain unit tests — no Laravel, no DB (Domain has no framework deps).
 * RED until Money is implemented (Phase 1, test-first).
 */
final class MoneyTest extends TestCase
{
    public function test_from_cents_preserves_exact_cents(): void
    {
        $m = Money::fromCents(9999);
        $this->assertSame(9999, $m->cents);
    }

    public function test_default_currency_is_eur(): void
    {
        $this->assertSame('EUR', Money::fromCents(100)->currency);
    }

    public function test_plus_and_minus_same_currency(): void
    {
        $a = Money::fromCents(4500);
        $b = Money::fromCents(1000);
        $this->assertSame(5500, $a->plus($b)->cents);
        $this->assertSame(3500, $a->minus($b)->cents);
    }

    public function test_plus_rejects_currency_mismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromCents(100, 'EUR')->plus(Money::fromCents(100, 'BGN'));
    }

    public function test_negative_amount_is_flagged(): void
    {
        $this->assertTrue(Money::fromCents(-1)->isNegative());
        $this->assertFalse(Money::fromCents(0)->isNegative());
    }

    public function test_allocate_loses_no_cents_for_equal_weights(): void
    {
        $parts = Money::fromCents(100)->allocate([1, 1, 1]);
        $this->assertSame(100, array_sum(array_map(fn (Money $p) => $p->cents, $parts)));
        // Largest-remainder, earliest-index bias:
        $this->assertSame([34, 33, 33], array_map(fn (Money $p) => $p->cents, $parts));
    }

    public function test_allocate_one_cent_by_45_45_10(): void
    {
        $parts = Money::fromCents(1)->allocate([4500, 4500, 1000]);
        $this->assertSame(1, array_sum(array_map(fn (Money $p) => $p->cents, $parts)));
        $this->assertSame([1, 0, 0], array_map(fn (Money $p) => $p->cents, $parts));
    }

    public function test_allocate_9999_by_45_45_10_reconciles(): void
    {
        // GENERIC largest-remainder primitive (fair). NOTE: the settlement-level
        // rule "operations absorbs the residual" is a SettlementCalculator concern,
        // NOT this primitive — allocate() is used for fair sub-pools (co-authors, NGOs).
        // floors [4499,4499,999]=9997; remainders [.55,.55,.90] → +1 to idx2 then idx0.
        $parts = Money::fromCents(9999)->allocate([4500, 4500, 1000]);
        $cents = array_map(fn (Money $p) => $p->cents, $parts);
        $this->assertSame(9999, array_sum($cents));
        $this->assertSame([4500, 4499, 1000], $cents);
    }
}
