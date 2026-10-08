<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Financial\RunReconciliation;
use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\Money;
use App\Domain\Settlement\RecipientLine;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Match scenarios
    // -----------------------------------------------------------------------

    public function test_reconciliation_matches_when_stripe_equals_internal(): void
    {
        $this->createSettlement('pi_rec_1', 'evt_rec_1', 10000);

        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   10000,
            stripeTransferredCents: 0,
        );

        $this->assertTrue($run->isMatched());
        $this->assertSame(0, $run->delta_received_cents);
        $this->assertSame(0, $run->delta_transferred_cents);
        $this->assertSame('matched', $run->status);
        $this->assertNotNull($run->completed_at);
    }

    public function test_reconciliation_matches_with_zero_activity(): void
    {
        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   0,
            stripeTransferredCents: 0,
        );

        $this->assertTrue($run->isMatched());
        $this->assertSame(0, $run->internal_settlement_count);
        $this->assertSame(0, $run->internal_gross_cents);
    }

    public function test_reconciliation_counts_multiple_settlements(): void
    {
        $this->createSettlement('pi_rec_2', 'evt_rec_2', 10000);
        $this->createSettlement('pi_rec_3', 'evt_rec_3', 5000);

        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   15000,
            stripeTransferredCents: 0,
        );

        $this->assertSame(2, $run->internal_settlement_count);
        $this->assertSame(15000, $run->internal_gross_cents);
        $this->assertTrue($run->isMatched());
    }

    // -----------------------------------------------------------------------
    // Mismatch scenarios
    // -----------------------------------------------------------------------

    public function test_reconciliation_mismatches_when_stripe_differs(): void
    {
        $this->createSettlement('pi_mis_1', 'evt_mis_1', 10000);

        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   9999, // one cent off
            stripeTransferredCents: 0,
        );

        $this->assertTrue($run->isMismatched());
        $this->assertSame(1, $run->delta_received_cents);
        $this->assertSame('mismatched', $run->status);
    }

    public function test_reconciliation_mismatch_on_transferred_side(): void
    {
        $this->createSettlement('pi_mis_2', 'evt_mis_2', 10000);

        // Mark outbox row as completed to simulate transferred amount
        DB::table('transfer_outbox')->where('status', 'pending')->update(['status' => 'completed']);

        $internalTransferred = (int) DB::table('transfer_outbox')->where('status', 'completed')->sum('amount_cents');

        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   10000,
            stripeTransferredCents: $internalTransferred + 500, // Stripe says 500 more than internal
        );

        $this->assertTrue($run->isMismatched());
        $this->assertSame(-500, $run->delta_transferred_cents);
    }

    // -----------------------------------------------------------------------
    // Period scoping
    // -----------------------------------------------------------------------

    public function test_settlements_outside_period_are_excluded(): void
    {
        // Settlement from yesterday — outside today's reconciliation window
        DB::table('settlements')->insert([
            'stripe_payment_intent_id' => 'pi_old',
            'stripe_event_id'          => 'evt_old',
            'gross_cents'              => 50000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 22500,
            'fund_cents'               => 22500,
            'ops_cents'                => 5000,
            'status'                   => 'pending',
            'created_at'               => now()->subDays(2),
            'updated_at'               => now()->subDays(2),
        ]);

        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   0,
            stripeTransferredCents: 0,
        );

        // Old settlement must not appear in today's reconciliation
        $this->assertSame(0, $run->internal_gross_cents);
        $this->assertTrue($run->isMatched());
    }

    // -----------------------------------------------------------------------
    // Model helpers
    // -----------------------------------------------------------------------

    public function test_reconciliation_run_linked_to_operator(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $run = (new RunReconciliation())->execute(
            periodStart:           now()->startOfDay(),
            periodEnd:             now()->endOfDay(),
            stripeReceivedCents:   0,
            stripeTransferredCents: 0,
            runBy:                 $admin,
        );

        $this->assertSame($admin->id, $run->run_by);
        $this->assertInstanceOf(User::class, $run->runner);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createSettlement(string $pi, string $evt, int $grossCents): int
    {
        $profile    = SplitProfile::fromKey('social_pilot_45_45_10');
        $result     = (new SettlementCalculator())->calculate(Money::fromCents($grossCents, 'EUR'), $profile);

        return (new CreateSettlement())->execute(
            result:        $result,
            paymentIntent: $pi,
            stripeEvent:   $evt,
            recipients:    [
                new RecipientLine(type: 'artist', amount: $result->artist, entityName: 'A', entityRole: 'artist', weight: 4500),
                new RecipientLine(type: 'fund',   amount: $result->fund,   entityName: 'F', entityRole: 'fund',   weight: 4500),
            ],
        );
    }
}
