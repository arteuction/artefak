<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Domain\Settlement\Money;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use PHPUnit\Framework\TestCase;

/**
 * Phase 78 — Settlement channel invariants.
 *
 * Documents which SplitProfile key applies to each sale channel and proves
 * the calculator produces the correct allocation per channel.
 *
 * Bulgaria adopted EUR on 2026-01-01. All ARTeuCtion settlements are in EUR.
 *
 * Sale channel → SplitProfile:
 *   Auction (phygital/pilot) → 'social_pilot_45_45_10'  (artist 45% / fund 45% / ops 10%)
 *   Sell Now                 → 'social_pilot_45_45_10'  (same; lot.split_profile_key overrides)
 *   Digital Library          → 'library_80_10_10'       (author 80% / fund 10% / ops 10%)
 */
final class SettlementChannelInvariantTest extends TestCase
{
    private SettlementCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SettlementCalculator();
    }

    // ── Auction channel ───────────────────────────────────────────────────────

    public function test_auction_channel_uses_social_pilot_45_45_10(): void
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');

        $this->assertSame(4500, $profile->artistBps());
        $this->assertSame(4500, $profile->fundBps());
        $this->assertSame(1000, $profile->operationsBps());
        $this->assertSame(10000, $profile->artistBps() + $profile->fundBps() + $profile->operationsBps());
    }

    public function test_auction_channel_eur_100_lot_splits_correctly(): void
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $gross   = Money::fromCents(10000, 'EUR');

        $result = $this->calc->calculate($gross, $profile);

        $this->assertSame(4500, $result->artist->cents);  // €45
        $this->assertSame(4500, $result->fund->cents);    // €45
        $this->assertSame(1000, $result->ops->cents);     // €10
        $this->assertSame('EUR', $result->artist->currency);
        $this->assertSame(10000, $result->artist->cents + $result->fund->cents + $result->ops->cents);
    }

    // ── Sell Now channel ──────────────────────────────────────────────────────

    public function test_sell_now_channel_uses_social_pilot_45_45_10_by_default(): void
    {
        // Sell Now uses the same profile as auctions unless the lot overrides via split_profile_key.
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');

        $gross  = Money::fromCents(50000, 'EUR'); // €500 offer
        $result = $this->calc->calculate($gross, $profile);

        $this->assertSame(22500, $result->artist->cents); // €225
        $this->assertSame(22500, $result->fund->cents);   // €225
        $this->assertSame(5000,  $result->ops->cents);    // €50
        $this->assertSame('social_pilot_45_45_10', $result->profileKey);
    }

    // ── Digital Library channel ───────────────────────────────────────────────

    public function test_library_channel_uses_library_80_10_10(): void
    {
        $profile = SplitProfile::fromKey('library_80_10_10');

        $this->assertSame(8000, $profile->artistBps());
        $this->assertSame(1000, $profile->fundBps());
        $this->assertSame(1000, $profile->operationsBps());
    }

    public function test_library_channel_eur_100_splits_correctly(): void
    {
        $profile = SplitProfile::fromKey('library_80_10_10');
        $gross   = Money::fromCents(10000, 'EUR');

        $result = $this->calc->calculate($gross, $profile);

        $this->assertSame(8000, $result->artist->cents); // €80
        $this->assertSame(1000, $result->fund->cents);   // €10
        $this->assertSame(1000, $result->ops->cents);    // €10
    }

    // ── Profile isolation ─────────────────────────────────────────────────────

    public function test_unknown_profile_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown split profile/');
        SplitProfile::fromKey('non_existent_profile');
    }

    public function test_profile_version_is_frozen_on_result(): void
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $result  = $this->calc->calculate(Money::fromCents(10000, 'EUR'), $profile);

        $this->assertGreaterThan(0, $result->profileVersion);
        $this->assertSame($profile->version(), $result->profileVersion);
    }

    // ── Currency guard ────────────────────────────────────────────────────────

    public function test_non_eur_currency_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate(
            Money::fromCents(10000, 'USD'),
            SplitProfile::fromKey('social_pilot_45_45_10'),
        );
    }
}
