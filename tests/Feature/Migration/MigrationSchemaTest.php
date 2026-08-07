<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies that the Phase 1c migrations created the expected tables
 * with the correct columns, indexes, and constraints.
 *
 * Guards against silent schema drift and ensures the idempotency
 * UNIQUEs are actually enforced at the DB level.
 */
class MigrationSchemaTest extends TestCase
{
    use RefreshDatabase;
    // ──────────────────────────────────────────────────────────────
    // settlements
    // ──────────────────────────────────────────────────────────────

    public function test_settlements_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('settlements'));
    }

    public function test_settlements_has_required_columns(): void
    {
        foreach ([
            'id', 'stripe_payment_intent_id', 'stripe_event_id',
            'auction_id', 'gross_cents', 'currency',
            'profile_key', 'profile_version',
            'artist_bps', 'fund_bps', 'ops_bps',
            'artist_cents', 'fund_cents', 'ops_cents',
            'status', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('settlements', $col),
                "settlements.{$col} is missing"
            );
        }
    }

    public function test_settlements_stripe_payment_intent_unique(): void
    {
        DB::table('settlements')->insert($this->baseSettlement(['stripe_payment_intent_id' => 'pi_AAA', 'stripe_event_id' => 'evt_AAA']));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('settlements')->insert($this->baseSettlement(['stripe_payment_intent_id' => 'pi_AAA', 'stripe_event_id' => 'evt_BBB']));
    }

    public function test_settlements_stripe_event_unique(): void
    {
        DB::table('settlements')->insert($this->baseSettlement(['stripe_payment_intent_id' => 'pi_CCC', 'stripe_event_id' => 'evt_CCC']));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('settlements')->insert($this->baseSettlement(['stripe_payment_intent_id' => 'pi_DDD', 'stripe_event_id' => 'evt_CCC']));
    }

    // ──────────────────────────────────────────────────────────────
    // settlement_lines
    // ──────────────────────────────────────────────────────────────

    public function test_settlement_lines_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('settlement_lines'));
    }

    public function test_settlement_lines_has_required_columns(): void
    {
        foreach ([
            'id', 'settlement_id', 'recipient_type',
            'legal_entity_id', 'entity_name', 'entity_eik', 'entity_role',
            'stripe_account_id', 'stripe_transfer_id',
            'amount_cents', 'currency', 'weight', 'status',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('settlement_lines', $col),
                "settlement_lines.{$col} is missing"
            );
        }
    }

    public function test_settlement_lines_stripe_transfer_id_unique(): void
    {
        $sid = $this->insertBaseSettlement('pi_E1', 'evt_E1');
        DB::table('settlement_lines')->insert($this->baseLine($sid, ['recipient_type' => 'artist', 'legal_entity_id' => 1, 'stripe_transfer_id' => 'tr_AAA']));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('settlement_lines')->insert($this->baseLine($sid, ['recipient_type' => 'fund', 'legal_entity_id' => 2, 'stripe_transfer_id' => 'tr_AAA']));
    }

    public function test_settlement_lines_unique_recipient_per_settlement(): void
    {
        $sid = $this->insertBaseSettlement('pi_F1', 'evt_F1');
        DB::table('settlement_lines')->insert($this->baseLine($sid, ['recipient_type' => 'artist', 'legal_entity_id' => 10]));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('settlement_lines')->insert($this->baseLine($sid, ['recipient_type' => 'artist', 'legal_entity_id' => 10]));
    }

    // ──────────────────────────────────────────────────────────────
    // ledger_entries
    // ──────────────────────────────────────────────────────────────

    public function test_ledger_entries_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('ledger_entries'));
    }

    public function test_ledger_entries_has_required_columns(): void
    {
        foreach ([
            'id', 'settlement_id', 'settlement_line_id',
            'type', 'amount_cents', 'currency', 'note',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('ledger_entries', $col),
                "ledger_entries.{$col} is missing"
            );
        }
    }


    public function test_ledger_entries_allows_multiple_debits_per_line(): void
    {
        // The (settlement_line_id, type) unique was removed in migration 000011 to allow
        // multiple debit rows per line (required for cumulative partial refunds).
        // Credit uniqueness is enforced at the application layer (CreateSettlement).
        $sid = $this->insertBaseSettlement('pi_G1', 'evt_G1');
        $lid = DB::table('settlement_lines')->insertGetId(
            $this->baseLine($sid, ['recipient_type' => 'fund', 'legal_entity_id' => 99])
        );

        DB::table('ledger_entries')->insert(['settlement_id' => $sid, 'settlement_line_id' => $lid, 'type' => 'debit', 'amount_cents' => 500, 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ledger_entries')->insert(['settlement_id' => $sid, 'settlement_line_id' => $lid, 'type' => 'debit', 'amount_cents' => 500, 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(2, (int) DB::table('ledger_entries')->where('settlement_line_id', $lid)->where('type', 'debit')->count());
    }

    // ──────────────────────────────────────────────────────────────
    // transfer_outbox
    // ──────────────────────────────────────────────────────────────

    public function test_transfer_outbox_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('transfer_outbox'));
    }

    public function test_transfer_outbox_has_required_columns(): void
    {
        foreach ([
            'id', 'settlement_line_id', 'stripe_account_id',
            'amount_cents', 'currency', 'stripe_idempotency_key',
            'status', 'attempt', 'last_error', 'next_attempt_at',
            'stripe_transfer_id', 'dispatched_at',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('transfer_outbox', $col),
                "transfer_outbox.{$col} is missing"
            );
        }
    }

    public function test_transfer_outbox_idempotency_key_unique(): void
    {
        $sid = $this->insertBaseSettlement('pi_H1', 'evt_H1');
        $lid = DB::table('settlement_lines')->insertGetId($this->baseLine($sid, ['recipient_type' => 'artist', 'legal_entity_id' => 5]));

        DB::table('transfer_outbox')->insert($this->baseOutbox($lid, ['stripe_idempotency_key' => 'idem_AAA', 'attempt' => 0]));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('transfer_outbox')->insert($this->baseOutbox($lid, ['stripe_idempotency_key' => 'idem_AAA', 'attempt' => 1]));
    }

    public function test_transfer_outbox_line_attempt_unique(): void
    {
        $sid = $this->insertBaseSettlement('pi_I1', 'evt_I1');
        $lid = DB::table('settlement_lines')->insertGetId($this->baseLine($sid, ['recipient_type' => 'ops', 'legal_entity_id' => 6]));

        DB::table('transfer_outbox')->insert($this->baseOutbox($lid, ['stripe_idempotency_key' => 'idem_B0', 'attempt' => 0]));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('transfer_outbox')->insert($this->baseOutbox($lid, ['stripe_idempotency_key' => 'idem_B1', 'attempt' => 0]));
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function baseSettlement(array $override = []): array
    {
        return array_merge([
            'stripe_payment_intent_id' => 'pi_test_' . uniqid(),
            'stripe_event_id'          => 'evt_test_' . uniqid(),
            'gross_cents'              => 10000,
            'currency'                 => 'EUR',
            'profile_key'              => 'social_pilot_45_45_10',
            'profile_version'          => 1,
            'artist_bps'               => 4500,
            'fund_bps'                 => 4500,
            'ops_bps'                  => 1000,
            'artist_cents'             => 4500,
            'fund_cents'               => 4500,
            'ops_cents'                => 1000,
            'status'                   => 'pending',
            'created_at'               => now(),
            'updated_at'               => now(),
        ], $override);
    }

    private function insertBaseSettlement(string $pi, string $evt): int
    {
        return (int) DB::table('settlements')->insertGetId($this->baseSettlement([
            'stripe_payment_intent_id' => $pi,
            'stripe_event_id'          => $evt,
        ]));
    }

    private function baseLine(int $settlementId, array $override = []): array
    {
        return array_merge([
            'settlement_id'    => $settlementId,
            'recipient_type'   => 'artist',
            'legal_entity_id'  => null,
            'amount_cents'     => 4500,
            'currency'         => 'EUR',
            'weight'           => 1,
            'status'           => 'pending',
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $override);
    }

    private function baseOutbox(int $lineId, array $override = []): array
    {
        return array_merge([
            'settlement_line_id'     => $lineId,
            'stripe_account_id'      => 'acct_test',
            'amount_cents'           => 4500,
            'currency'               => 'EUR',
            'stripe_idempotency_key' => 'idem_' . uniqid(),
            'status'                 => 'pending',
            'attempt'                => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ], $override);
    }
}
