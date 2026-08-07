<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\Money;
use App\Domain\Settlement\RecipientLine;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateSettlementTest extends TestCase
{
    use RefreshDatabase;
    private CreateSettlement $action;
    private array $baseRecipients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CreateSettlement();

        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        $calc    = new SettlementCalculator();
        $result  = $calc->calculate(Money::fromCents(10000, 'EUR'), $profile);

        $this->baseRecipients = [
            new RecipientLine('artist', $result->artist, legalEntityId: 1, entityName: 'Ivan Petrov', entityEik: '1234567890', entityRole: 'artist',          stripeAccountId: 'acct_artist'),
            new RecipientLine('fund',   $result->fund,   legalEntityId: 2, entityName: 'ArteUction Foundation', entityEik: '9876543210', entityRole: 'fund_custodian', stripeAccountId: 'acct_fund'),
            new RecipientLine('ops',    $result->ops,    legalEntityId: 3, entityName: 'ArteUction OOD',        entityEik: '1111111111', entityRole: 'operator',       stripeAccountId: 'acct_ops'),
        ];
    }

    // ── Happy path ────────────────────────────────────────────────

    public function test_creates_settlement_row(): void
    {
        $id = $this->action->execute(
            $this->makeResult(10000),
            'pi_happy',
            'evt_happy',
            $this->baseRecipients,
        );

        $row = DB::table('settlements')->find($id);
        $this->assertNotNull($row);
        $this->assertSame('pi_happy', $row->stripe_payment_intent_id);
        $this->assertSame('evt_happy', $row->stripe_event_id);
        $this->assertSame(10000, (int) $row->gross_cents);
        $this->assertSame(4500,  (int) $row->artist_cents);
        $this->assertSame(4500,  (int) $row->fund_cents);
        $this->assertSame(1000,  (int) $row->ops_cents);
        $this->assertSame('pending', $row->status);
    }

    public function test_creates_three_settlement_lines(): void
    {
        $id = $this->action->execute($this->makeResult(10000), 'pi_lines', 'evt_lines', $this->baseRecipients);

        $lines = DB::table('settlement_lines')->where('settlement_id', $id)->get();
        $this->assertCount(3, $lines);

        $byType = $lines->keyBy('recipient_type');
        $this->assertSame(4500, (int) $byType['artist']->amount_cents);
        $this->assertSame(4500, (int) $byType['fund']->amount_cents);
        $this->assertSame(1000, (int) $byType['ops']->amount_cents);

        // Frozen snapshots
        $this->assertSame('Ivan Petrov', $byType['artist']->entity_name);
        $this->assertSame('1234567890',  $byType['artist']->entity_eik);
    }

    public function test_creates_ledger_credit_for_all_three_parties(): void
    {
        $id      = $this->action->execute($this->makeResult(10000), 'pi_ledger', 'evt_ledger', $this->baseRecipients);
        $entries = DB::table('ledger_entries')->where('settlement_id', $id)->get();

        $this->assertCount(3, $entries);
        foreach ($entries as $e) {
            $this->assertSame('credit', $e->type);
            $this->assertSame('Sale split', $e->note);
        }
        $total = $entries->sum('amount_cents');
        $this->assertSame(10000, (int) $total);
    }

    public function test_creates_outbox_row_for_each_line_with_stripe_account(): void
    {
        $id = $this->action->execute($this->makeResult(10000), 'pi_outbox', 'evt_outbox', $this->baseRecipients);

        $lineIds = DB::table('settlement_lines')->where('settlement_id', $id)->pluck('id');
        $outbox  = DB::table('transfer_outbox')->whereIn('settlement_line_id', $lineIds)->get();

        $this->assertCount(3, $outbox);
        foreach ($outbox as $row) {
            $this->assertSame('pending', $row->status);
            $this->assertSame(0, (int) $row->attempt);
            $this->assertStringStartsWith('pi_outbox_', $row->stripe_idempotency_key);
        }
    }

    public function test_no_outbox_row_for_line_without_stripe_account(): void
    {
        $recipients = [
            new RecipientLine('artist', Money::fromCents(4500, 'EUR'), stripeAccountId: null),
            new RecipientLine('fund',   Money::fromCents(4500, 'EUR'), stripeAccountId: 'acct_fund'),
            new RecipientLine('ops',    Money::fromCents(1000, 'EUR'), stripeAccountId: null),
        ];

        $id      = $this->action->execute($this->makeResult(10000), 'pi_noacct', 'evt_noacct', $recipients);
        $lineIds = DB::table('settlement_lines')->where('settlement_id', $id)->pluck('id');
        $outbox  = DB::table('transfer_outbox')->whereIn('settlement_line_id', $lineIds)->get();

        $this->assertCount(1, $outbox);
        $this->assertSame('acct_fund', $outbox[0]->stripe_account_id);
    }

    // ── Idempotency ───────────────────────────────────────────────

    public function test_second_call_with_same_payment_intent_returns_same_id(): void
    {
        $id1 = $this->action->execute($this->makeResult(10000), 'pi_idem', 'evt_idem1', $this->baseRecipients);
        $id2 = $this->action->execute($this->makeResult(10000), 'pi_idem', 'evt_idem2', $this->baseRecipients);

        $this->assertSame($id1, $id2);
    }

    public function test_idempotent_call_does_not_insert_duplicate_lines(): void
    {
        $this->action->execute($this->makeResult(10000), 'pi_idem2', 'evt_X1', $this->baseRecipients);
        $this->action->execute($this->makeResult(10000), 'pi_idem2', 'evt_X2', $this->baseRecipients);

        $count = DB::table('settlement_lines')
            ->join('settlements', 'settlements.id', '=', 'settlement_lines.settlement_id')
            ->where('settlements.stripe_payment_intent_id', 'pi_idem2')
            ->count();

        $this->assertSame(3, $count);
    }

    public function test_idempotent_call_does_not_insert_duplicate_ledger_entries(): void
    {
        $this->action->execute($this->makeResult(10000), 'pi_idem3', 'evt_Y1', $this->baseRecipients);
        $this->action->execute($this->makeResult(10000), 'pi_idem3', 'evt_Y2', $this->baseRecipients);

        $count = DB::table('ledger_entries')
            ->join('settlements', 'settlements.id', '=', 'ledger_entries.settlement_id')
            ->where('settlements.stripe_payment_intent_id', 'pi_idem3')
            ->count();

        $this->assertSame(3, $count); // one credit per party
    }

    // ── Gross total invariant ─────────────────────────────────────

    public function test_artist_plus_fund_plus_ops_equals_gross(): void
    {
        foreach ([10001, 99999, 1, 3, 7] as $cents) {
            $result = $this->makeResult($cents);
            $id     = $this->action->execute(
                $result,
                'pi_sum_' . $cents,
                'evt_sum_' . $cents,
                [
                    new RecipientLine('artist', $result->artist),
                    new RecipientLine('fund',   $result->fund),
                    new RecipientLine('ops',    $result->ops),
                ],
            );
            $row = DB::table('settlements')->find($id);
            $this->assertSame(
                (int) $row->gross_cents,
                (int) $row->artist_cents + (int) $row->fund_cents + (int) $row->ops_cents,
                "Invariant failed for gross={$cents}"
            );
        }
    }

    // ── Helper ────────────────────────────────────────────────────

    private function makeResult(int $cents): \App\Domain\Settlement\SettlementResult
    {
        $profile = SplitProfile::fromKey('social_pilot_45_45_10');
        return (new SettlementCalculator())->calculate(Money::fromCents($cents, 'EUR'), $profile);
    }
}
