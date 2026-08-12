<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use App\Domain\Library\BookSettlementCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookSettlementCalculatorTest extends TestCase
{
    private function calc(): BookSettlementCalculator
    {
        return new BookSettlementCalculator();
    }

    public function test_1000_cents_80_10_10_split(): void
    {
        $r = $this->calc()->calculate(1000, 'library_80_10_10');

        $this->assertSame(800, $r->author->cents);
        $this->assertSame(100, $r->fund->cents);
        $this->assertSame(100, $r->ops->cents);
    }

    public function test_parts_sum_to_split_base(): void
    {
        $r = $this->calc()->calculate(9999, 'library_80_10_10');

        $this->assertSame(9999, $r->author->cents + $r->fund->cents + $r->ops->cents);
    }

    public function test_ops_absorbs_rounding_remainder(): void
    {
        // 1 cent: 80% = 0, 10% = 0, ops = 1
        $r = $this->calc()->calculate(1, 'library_80_10_10');

        $this->assertSame(0, $r->author->cents);
        $this->assertSame(0, $r->fund->cents);
        $this->assertSame(1, $r->ops->cents);
    }

    public function test_zero_split_base_produces_zero_parts(): void
    {
        $r = $this->calc()->calculate(0, 'library_80_10_10');

        $this->assertSame(0, $r->author->cents);
        $this->assertSame(0, $r->fund->cents);
        $this->assertSame(0, $r->ops->cents);
    }

    public function test_profile_snapshot_is_frozen(): void
    {
        $r = $this->calc()->calculate(1000, 'library_80_10_10');

        $this->assertSame('library_80_10_10', $r->profileKey);
        $this->assertSame(8000, $r->authorBps);
        $this->assertSame(1000, $r->fundBps);
        $this->assertSame(1000, $r->opsBps);
    }

    public function test_negative_split_base_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc()->calculate(-1, 'library_80_10_10');
    }

    public function test_unknown_profile_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc()->calculate(1000, 'mystery_profile');
    }

    public function test_10000_cents_exact(): void
    {
        $r = $this->calc()->calculate(10000, 'library_80_10_10');

        $this->assertSame(8000, $r->author->cents);
        $this->assertSame(1000, $r->fund->cents);
        $this->assertSame(1000, $r->ops->cents);
    }
}
