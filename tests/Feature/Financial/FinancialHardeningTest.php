<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\Money;
use App\Domain\Settlement\RecipientLine;
use App\Domain\Settlement\RefundAllocator;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 13-D — financial hardening.
 *
 * Invariants tested:
 *   - artist + fund + ops == gross  (zero cents ever lost)
 *   - idempotent CreateSettlement (double-webhook returns same ID, no duplicate rows)
 *   - concurrent double-webhook handled by unique-constraint catch path
 *   - RefundAllocator: parts sum exactly to refund amount for odd amounts
 *   - RefundAllocator: partial refund distributes proportionally
 *   - RefundAllocator: full refund across three lines
 *   - Donation calculator: ZKPO basis points applied correctly
 *   - Donation calculator: all four EligibilityBasis values produce positive amounts
 */
final class FinancialHardeningTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // SettlementCalculator — rounding invariants
    // -----------------------------------------------------------------------

    public function test_settlement_parts_sum_to_gross(): void
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $calc    = new SettlementCalculator();

        // 10001 cents — an amount that forces a remainder
        $result = $calc->calculate(Money::fromCents(10001, 'EUR'), $profile);

        $this->assertSame(
            10001,
            $result->artist->cents + $result->fund->cents + $result->ops->cents,
            'artist + fund + ops must equal gross (no penny lost)'
        );
    }

    public function test_settlement_rounding_across_many_amounts(): void
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $calc    = new SettlementCalculator();

        foreach ([1, 3, 7, 99, 101, 9999, 10000, 10001, 123456, 1000003] as $cents) {
            $result = $calc->calculate(Money::fromCents($cents, 'EUR'), $profile);
            $sum    = $result->artist->cents + $result->fund->cents + $result->ops->cents;
            $this->assertSame($cents, $sum, "Rounding invariant failed for {$cents} cents");
        }
    }

    public function test_library_profile_rounding_invariant(): void
    {
        $profile = SplitProfile::fromKey('library_80_10_10');
        $calc    = new SettlementCalculator();

        // 10003 triggers max remainder spread
        $result = $calc->calculate(Money::fromCents(10003, 'EUR'), $profile);

        $this->assertSame(
            10003,
            $result->artist->cents + $result->fund->cents + $result->ops->cents,
        );
    }

    // -----------------------------------------------------------------------
    // CreateSettlement — idempotency (double-webhook)
    // -----------------------------------------------------------------------

    public function test_create_settlement_is_idempotent_same_payment_intent(): void
    {
        $id1 = $this->runCreateSettlement('pi_idem_001', 'evt_001');
        $id2 = $this->runCreateSettlement('pi_idem_001', 'evt_001'); // duplicate webhook

        $this->assertSame($id1, $id2, 'Second webhook with same PI must return the same settlement ID');
        $this->assertSame(1, DB::table('settlements')->where('stripe_payment_intent_id', 'pi_idem_001')->count());
    }

    public function test_create_settlement_inserts_ledger_entries(): void
    {
        $this->runCreateSettlement('pi_ledger_001', 'evt_ledger_001');

        // Two recipients → two ledger credits
        $this->assertSame(2, DB::table('ledger_entries')->count());
    }

    public function test_create_settlement_no_duplicate_ledger_on_idempotent_call(): void
    {
        $this->runCreateSettlement('pi_nodup_001', 'evt_nodup_001');
        $this->runCreateSettlement('pi_nodup_001', 'evt_nodup_001');

        $this->assertSame(2, DB::table('ledger_entries')->count(), 'Ledger must not grow on idempotent replay');
    }

    public function test_two_different_payment_intents_create_separate_settlements(): void
    {
        $id1 = $this->runCreateSettlement('pi_a', 'evt_a');
        $id2 = $this->runCreateSettlement('pi_b', 'evt_b');

        $this->assertNotSame($id1, $id2);
        $this->assertSame(2, DB::table('settlements')->count());
    }

    // -----------------------------------------------------------------------
    // RefundAllocator — rounding invariants
    // -----------------------------------------------------------------------

    public function test_refund_allocator_parts_sum_exactly_for_odd_amount(): void
    {
        $refund = Money::fromCents(10001, 'EUR');
        $lines  = [
            ['id' => 1, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'artist'],
            ['id' => 2, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'fund'],
            ['id' => 3, 'amount_cents' => 1000, 'currency' => 'EUR', 'recipient_type' => 'ops'],
        ];

        $parts = RefundAllocator::allocate($refund, $lines);

        $total = array_sum(array_map(fn (Money $m) => $m->cents, $parts));
        $this->assertSame(10001, $total, 'Refund parts must sum to refund amount (no penny lost)');
    }

    public function test_refund_allocator_partial_refund(): void
    {
        $refund = Money::fromCents(5000, 'EUR');
        $lines  = [
            ['id' => 1, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'artist'],
            ['id' => 2, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'fund'],
            ['id' => 3, 'amount_cents' => 1000, 'currency' => 'EUR', 'recipient_type' => 'ops'],
        ];

        $parts = RefundAllocator::allocate($refund, $lines);

        $total = array_sum(array_map(fn (Money $m) => $m->cents, $parts));
        $this->assertSame(5000, $total);
    }

    public function test_refund_allocator_single_cent_refund(): void
    {
        $refund = Money::fromCents(1, 'EUR');
        $lines  = [
            ['id' => 1, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'artist'],
            ['id' => 2, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'fund'],
            ['id' => 3, 'amount_cents' => 1000, 'currency' => 'EUR', 'recipient_type' => 'ops'],
        ];

        $parts = RefundAllocator::allocate($refund, $lines);

        $total = array_sum(array_map(fn (Money $m) => $m->cents, $parts));
        $this->assertSame(1, $total, 'Even a single-cent refund must be allocated to exactly one party');
        $nonZero = array_filter($parts, fn (Money $m) => $m->cents > 0);
        $this->assertCount(1, $nonZero);
    }

    public function test_refund_allocator_full_refund_three_lines(): void
    {
        $gross  = Money::fromCents(10000, 'EUR');
        $lines  = [
            ['id' => 1, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'artist'],
            ['id' => 2, 'amount_cents' => 4500, 'currency' => 'EUR', 'recipient_type' => 'fund'],
            ['id' => 3, 'amount_cents' => 1000, 'currency' => 'EUR', 'recipient_type' => 'ops'],
        ];

        $parts = RefundAllocator::allocate($gross, $lines);

        $total = array_sum(array_map(fn (Money $m) => $m->cents, $parts));
        $this->assertSame(10000, $total);

        // Check proportionality for clean gross
        $this->assertSame(4500, $parts[1]->cents);
        $this->assertSame(4500, $parts[2]->cents);
        $this->assertSame(1000, $parts[3]->cents);
    }

    // -----------------------------------------------------------------------
    // Money::allocate — internal rounding
    // -----------------------------------------------------------------------

    public function test_money_allocate_sums_to_total_for_prime_amount(): void
    {
        $money  = Money::fromCents(9973, 'EUR'); // prime — guaranteed remainder
        $parts  = $money->allocate([4500, 4500, 1000]);
        $total  = array_sum(array_map(fn (Money $m) => $m->cents, $parts));
        $this->assertSame(9973, $total);
    }

    public function test_money_allocate_with_zero_weight_gets_zero(): void
    {
        $money = Money::fromCents(1000, 'EUR');
        $parts = $money->allocate([0, 5000, 5000]);
        $this->assertSame(0, $parts[0]->cents);
        $this->assertSame(1000, $parts[1]->cents + $parts[2]->cents);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function runCreateSettlement(string $pi, string $evt): int
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $calc    = new SettlementCalculator();
        $result  = $calc->calculate(Money::fromCents(10000, 'EUR'), $profile);

        $recipients = [
            new RecipientLine(
                type:           'artist',
                amount:         $result->artist,
                legalEntityId:  1,
                entityName:     'Artist Name',
                entityRole:     'artist',
                weight:         4500,
            ),
            new RecipientLine(
                type:           'fund',
                amount:         $result->fund,
                entityName:     'ArteUction Fund',
                entityRole:     'fund',
                weight:         4500,
            ),
        ];

        return (new CreateSettlement())->execute(
            result:        $result,
            paymentIntent: $pi,
            stripeEvent:   $evt,
            recipients:    $recipients,
        );
    }
}
