<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Domain\Settlement\SplitProfile;
use PHPUnit\Framework\TestCase;

/**
 * Pure domain unit tests — no Laravel, no DB.
 * RED until SplitProfile is implemented (Phase 1, test-first).
 */
final class SplitProfileTest extends TestCase
{
    public function test_social_pilot_profile_basis_points(): void
    {
        $p = SplitProfile::fromKey('social_pilot_45_45_10');
        $this->assertSame('social_pilot_45_45_10', $p->key());
        $this->assertSame(4500, $p->artistBps());
        $this->assertSame(4500, $p->fundBps());
        $this->assertSame(1000, $p->operationsBps());
    }

    public function test_basis_points_sum_to_ten_thousand(): void
    {
        $p = SplitProfile::fromKey('social_pilot_45_45_10');
        $this->assertSame(10000, $p->artistBps() + $p->fundBps() + $p->operationsBps());
    }

    public function test_unknown_profile_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SplitProfile::fromKey('mystery_profile');
    }

    /** P0 dropped 90/10 — it must be rejected like any other unknown key. */
    public function test_standard_90_10_is_dropped_for_p0(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SplitProfile::fromKey('standard_90_10');
    }

    public function test_version_is_frozen(): void
    {
        $this->assertSame(SplitProfile::VERSION, SplitProfile::fromKey('social_pilot_45_45_10')->version());
    }

    public function test_library_80_10_10_profile_basis_points(): void
    {
        $p = SplitProfile::fromKey('library_80_10_10');
        $this->assertSame('library_80_10_10', $p->key());
        $this->assertSame(8000, $p->artistBps());
        $this->assertSame(1000, $p->fundBps());
        $this->assertSame(1000, $p->operationsBps());
    }

    public function test_library_profile_basis_points_sum_to_ten_thousand(): void
    {
        $p = SplitProfile::fromKey('library_80_10_10');
        $this->assertSame(10000, $p->artistBps() + $p->fundBps() + $p->operationsBps());
    }
}
