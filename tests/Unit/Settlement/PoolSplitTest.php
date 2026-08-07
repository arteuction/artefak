<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Domain\Settlement\CoAuthorSplit;
use App\Domain\Settlement\FundSplit;
use App\Domain\Settlement\Money;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1b — sub-pool splits (pure domain, no DB/Laravel/Stripe).
 * RED by design until CoAuthorSplit / FundSplit are implemented.
 *
 * CoAuthorSplit: artist pool → co-authors by weight (int weights, equal = [1,1,...])
 * FundSplit:     fund pool   → NGOs        by weight
 *
 * Both use Money::allocate() internally — largest-remainder, earliest-index bias.
 */
final class PoolSplitTest extends TestCase
{
    // ─── CoAuthorSplit ────────────────────────────────────────────────────────

    public function test_single_author_gets_full_artist_pool(): void
    {
        $pool   = Money::fromCents(4500);
        $result = CoAuthorSplit::split($pool, ['author_1' => 1]);
        $this->assertSame(4500, $result['author_1']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_two_equal_authors_split_pool(): void
    {
        $pool   = Money::fromCents(4499); // odd cent → first author gets it
        $result = CoAuthorSplit::split($pool, ['a1' => 1, 'a2' => 1]);
        $this->assertSame(2250, $result['a1']->cents); // largest-remainder bias
        $this->assertSame(2249, $result['a2']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_three_authors_weighted(): void
    {
        // weights 2:2:1 on 1000 cents → 400:400:200
        $pool   = Money::fromCents(1000);
        $result = CoAuthorSplit::split($pool, ['a1' => 2, 'a2' => 2, 'a3' => 1]);
        $this->assertSame(400, $result['a1']->cents);
        $this->assertSame(400, $result['a2']->cents);
        $this->assertSame(200, $result['a3']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_coauthor_split_reconciles_odd_cents(): void
    {
        // 1 cent among 3 equal authors → first gets it
        $pool   = Money::fromCents(1);
        $result = CoAuthorSplit::split($pool, ['a1' => 1, 'a2' => 1, 'a3' => 1]);
        $this->assertSame(1, $result['a1']->cents);
        $this->assertSame(0, $result['a2']->cents);
        $this->assertSame(0, $result['a3']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_coauthor_split_preserves_currency(): void
    {
        $pool   = Money::fromCents(10000, 'EUR');
        $result = CoAuthorSplit::split($pool, ['a1' => 1]);
        $this->assertSame('EUR', $result['a1']->currency);
    }

    public function test_empty_authors_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CoAuthorSplit::split(Money::fromCents(1000), []);
    }

    // ─── FundSplit ────────────────────────────────────────────────────────────

    public function test_single_ngo_gets_full_fund_pool(): void
    {
        $pool   = Money::fromCents(4500);
        $result = FundSplit::split($pool, ['ngo_1' => 1]);
        $this->assertSame(4500, $result['ngo_1']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_two_equal_ngos_split_fund(): void
    {
        $pool   = Money::fromCents(4499);
        $result = FundSplit::split($pool, ['ngo_1' => 1, 'ngo_2' => 1]);
        $this->assertSame(2250, $result['ngo_1']->cents);
        $this->assertSame(2249, $result['ngo_2']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_three_ngos_weighted(): void
    {
        // weights 3:2:1 on 600 cents → 300:200:100
        $pool   = Money::fromCents(600);
        $result = FundSplit::split($pool, ['ngo_1' => 3, 'ngo_2' => 2, 'ngo_3' => 1]);
        $this->assertSame(300, $result['ngo_1']->cents);
        $this->assertSame(200, $result['ngo_2']->cents);
        $this->assertSame(100, $result['ngo_3']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_fund_split_reconciles_odd_cents(): void
    {
        $pool   = Money::fromCents(1);
        $result = FundSplit::split($pool, ['ngo_1' => 1, 'ngo_2' => 1]);
        $this->assertSame(1, $result['ngo_1']->cents);
        $this->assertSame(0, $result['ngo_2']->cents);
        $this->assertReconciles($pool, $result);
    }

    public function test_empty_ngos_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FundSplit::split(Money::fromCents(1000), []);
    }

    // ─── Combined: SettlementCalculator + sub-splits reconcile end-to-end ────

    public function test_full_pipeline_reconciles_to_gross(): void
    {
        // €99.99 → artist=4499, fund=4499, ops=1001
        // 2 equal co-authors: [2250, 2249]
        // 2 equal NGOs:       [2250, 2249]
        $gross      = Money::fromCents(9999);
        $artistPool = Money::fromCents(4499);
        $fundPool   = Money::fromCents(4499);
        $ops        = Money::fromCents(1001);

        $authors = CoAuthorSplit::split($artistPool, ['a1' => 1, 'a2' => 1]);
        $ngos    = FundSplit::split($fundPool,   ['n1' => 1, 'n2' => 1]);

        $total = $ops->cents
            + array_sum(array_map(fn ($m) => $m->cents, $authors))
            + array_sum(array_map(fn ($m) => $m->cents, $ngos));

        $this->assertSame($gross->cents, $total,
            "End-to-end reconciliation failed: total={$total} != gross={$gross->cents}");
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function assertReconciles(Money $pool, array $parts): void
    {
        $sum = array_sum(array_map(fn ($m) => $m->cents, $parts));
        $this->assertSame($pool->cents, $sum,
            "Split does not reconcile: sum={$sum} != pool={$pool->cents}");
    }
}
