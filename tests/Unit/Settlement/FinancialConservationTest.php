<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Domain\Settlement\Money;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 142 — Financial conservation.
 *
 * Invariant: artist + fund + ops == gross for every split profile and
 * every gross amount (including rounding-heavy edge cases).
 *
 * No database access — pure domain logic only.
 */
class FinancialConservationTest extends TestCase
{
    private SettlementCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new SettlementCalculator();
    }

    // ── social_pilot_45_45_10 ──────────────────────────────────────────────────

    public function test_social_pilot_conservation_round_amount(): void
    {
        $gross   = Money::fromCents(10000, 'EUR');
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $result  = $this->calc->calculate($gross, $profile);

        $this->assertConservation($result->gross->cents, $result->artist->cents, $result->fund->cents, $result->ops->cents);
        $this->assertSame(4500, $result->artist->cents);
        $this->assertSame(4500, $result->fund->cents);
        $this->assertSame(1000, $result->ops->cents);
    }

    public function test_social_pilot_conservation_odd_cents(): void
    {
        // 1 cent: artist = floor(1 * 4500 / 10000) = 0, fund = 0, ops = 1
        $result = $this->calc->calculate(Money::fromCents(1, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));

        $this->assertConservation(1, $result->artist->cents, $result->fund->cents, $result->ops->cents);
        $this->assertSame(0, $result->artist->cents);
        $this->assertSame(0, $result->fund->cents);
        $this->assertSame(1, $result->ops->cents);
    }

    public function test_social_pilot_conservation_small_amounts(): void
    {
        foreach ([1, 2, 3, 7, 9, 10, 11, 99, 100, 101] as $cents) {
            $result = $this->calc->calculate(Money::fromCents($cents, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));
            $this->assertConservation($cents, $result->artist->cents, $result->fund->cents, $result->ops->cents,
                "gross={$cents}");
        }
    }

    public function test_social_pilot_conservation_typical_prices(): void
    {
        // Typical EUR artwork prices: €500, €1 200, €5 000, €12 750, €100 000
        foreach ([50000, 120000, 500000, 1275000, 10000000] as $cents) {
            $result = $this->calc->calculate(Money::fromCents($cents, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));
            $this->assertConservation($cents, $result->artist->cents, $result->fund->cents, $result->ops->cents,
                "gross={$cents}");
        }
    }

    public function test_social_pilot_bps_frozen_in_result(): void
    {
        $result = $this->calc->calculate(Money::fromCents(10000, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));

        $this->assertSame(4500, $result->artistBps);
        $this->assertSame(4500, $result->fundBps);
        $this->assertSame(1000, $result->opsBps);
    }

    public function test_social_pilot_profile_key_and_version_frozen(): void
    {
        $result = $this->calc->calculate(Money::fromCents(10000, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));

        $this->assertSame('social_pilot_45_45_10', $result->profileKey);
        $this->assertSame(1, $result->profileVersion);
    }

    // ── library_80_10_10 ──────────────────────────────────────────────────────

    public function test_library_conservation_round_amount(): void
    {
        $gross   = Money::fromCents(10000, 'EUR');
        $profile = SplitProfile::fromKey('library_80_10_10');
        $result  = $this->calc->calculate($gross, $profile);

        $this->assertConservation(10000, $result->artist->cents, $result->fund->cents, $result->ops->cents);
        $this->assertSame(8000, $result->artist->cents);
        $this->assertSame(1000, $result->fund->cents);
        $this->assertSame(1000, $result->ops->cents);
    }

    public function test_library_conservation_odd_cents(): void
    {
        foreach ([1, 3, 7, 9, 11, 99, 101, 1001] as $cents) {
            $result = $this->calc->calculate(Money::fromCents($cents, 'EUR'), SplitProfile::fromKey('library_80_10_10'));
            $this->assertConservation($cents, $result->artist->cents, $result->fund->cents, $result->ops->cents,
                "gross={$cents}");
        }
    }

    public function test_library_bps_frozen_in_result(): void
    {
        $result = $this->calc->calculate(Money::fromCents(10000, 'EUR'), SplitProfile::fromKey('library_80_10_10'));

        $this->assertSame(8000, $result->artistBps);
        $this->assertSame(1000, $result->fundBps);
        $this->assertSame(1000, $result->opsBps);
    }

    // ── Zero amount ───────────────────────────────────────────────────────────

    public function test_zero_gross_conservation_social_pilot(): void
    {
        $result = $this->calc->calculate(Money::fromCents(0, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));

        $this->assertConservation(0, $result->artist->cents, $result->fund->cents, $result->ops->cents);
        $this->assertSame(0, $result->artist->cents);
        $this->assertSame(0, $result->fund->cents);
        $this->assertSame(0, $result->ops->cents);
    }

    public function test_zero_gross_conservation_library(): void
    {
        $result = $this->calc->calculate(Money::fromCents(0, 'EUR'), SplitProfile::fromKey('library_80_10_10'));

        $this->assertConservation(0, $result->artist->cents, $result->fund->cents, $result->ops->cents);
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function test_non_eur_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/EUR/');

        $this->calc->calculate(Money::fromCents(10000, 'USD'), SplitProfile::fromKey('social_pilot_45_45_10'));
    }

    public function test_negative_gross_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative/');

        $this->calc->calculate(Money::fromCents(-1, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));
    }

    public function test_unknown_split_profile_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown split profile/');

        SplitProfile::fromKey('nonexistent_profile');
    }

    // ── Rounding: ops absorbs the rounding remainder ──────────────────────────

    public function test_ops_absorbs_rounding_remainder_social_pilot(): void
    {
        // gross=3: artist=floor(3*4500/10000)=floor(1.35)=1, fund=1, ops=3-1-1=1
        $result = $this->calc->calculate(Money::fromCents(3, 'EUR'), SplitProfile::fromKey('social_pilot_45_45_10'));

        $this->assertSame(1, $result->artist->cents);
        $this->assertSame(1, $result->fund->cents);
        $this->assertSame(1, $result->ops->cents);
        $this->assertConservation(3, 1, 1, 1);
    }

    public function test_ops_absorbs_rounding_remainder_library(): void
    {
        // gross=3: artist=floor(3*8000/10000)=floor(2.4)=2, fund=floor(3*1000/10000)=0, ops=3-2-0=1
        $result = $this->calc->calculate(Money::fromCents(3, 'EUR'), SplitProfile::fromKey('library_80_10_10'));

        $this->assertSame(2, $result->artist->cents);
        $this->assertSame(0, $result->fund->cents);
        $this->assertSame(1, $result->ops->cents);
        $this->assertConservation(3, 2, 0, 1);
    }

    // ── All cents 0..200 exhaustive ───────────────────────────────────────────

    public function test_exhaustive_conservation_social_pilot_0_to_200(): void
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        for ($cents = 0; $cents <= 200; $cents++) {
            $result = $this->calc->calculate(Money::fromCents($cents, 'EUR'), $profile);
            $this->assertConservation($cents, $result->artist->cents, $result->fund->cents, $result->ops->cents,
                "gross={$cents}");
        }
    }

    public function test_exhaustive_conservation_library_0_to_200(): void
    {
        $profile = SplitProfile::fromKey('library_80_10_10');
        for ($cents = 0; $cents <= 200; $cents++) {
            $result = $this->calc->calculate(Money::fromCents($cents, 'EUR'), $profile);
            $this->assertConservation($cents, $result->artist->cents, $result->fund->cents, $result->ops->cents,
                "gross={$cents}");
        }
    }

    // ── SplitProfile invariants ───────────────────────────────────────────────

    public function test_split_profile_bps_sum_to_10000_social_pilot(): void
    {
        $p = SplitProfile::fromKey('social_pilot_45_45_10');
        $this->assertSame(10000, $p->artistBps() + $p->fundBps() + $p->operationsBps());
    }

    public function test_split_profile_bps_sum_to_10000_library(): void
    {
        $p = SplitProfile::fromKey('library_80_10_10');
        $this->assertSame(10000, $p->artistBps() + $p->fundBps() + $p->operationsBps());
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function assertConservation(int $gross, int $artist, int $fund, int $ops, string $msg = ''): void
    {
        $sum = $artist + $fund + $ops;
        $this->assertSame($gross, $sum,
            "Conservation violated{$this->fmtMsg($msg)}: artist({$artist}) + fund({$fund}) + ops({$ops}) = {$sum} ≠ gross({$gross})");
        $this->assertGreaterThanOrEqual(0, $artist, "artist negative{$this->fmtMsg($msg)}");
        $this->assertGreaterThanOrEqual(0, $fund,   "fund negative{$this->fmtMsg($msg)}");
        $this->assertGreaterThanOrEqual(0, $ops,    "ops negative{$this->fmtMsg($msg)}");
    }

    private function fmtMsg(string $msg): string
    {
        return $msg === '' ? '' : " ({$msg})";
    }
}
