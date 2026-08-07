<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Step 1: add nullable column first so existing rows survive
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->after('note');
        });

        // Step 2: backfill existing rows
        // Credits: settlement:{settlement_line_id}:credit
        DB::statement("UPDATE ledger_entries
                        SET idempotency_key = CONCAT('settlement:', settlement_line_id, ':credit')
                        WHERE type = 'credit'");
        // Debits (from migration 011 onward): refund:{refund_line_id}:debit
        DB::statement("UPDATE ledger_entries
                        SET idempotency_key = CONCAT('refund:', refund_line_id, ':debit')
                        WHERE type = 'debit' AND refund_line_id IS NOT NULL");

        // Step 3: make NOT NULL + UNIQUE
        DB::statement("ALTER TABLE ledger_entries
                        MODIFY COLUMN idempotency_key VARCHAR(255) NOT NULL");

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unique('idempotency_key', 'ledger_idempotency_key_unique');
        });

        // Step 4: change refund_line_id FK from SET NULL to RESTRICT (append-only ledger —
        //         a debit must never silently lose its refund_line reference)
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['refund_line_id']);
        });
        DB::statement("ALTER TABLE ledger_entries
                        ADD CONSTRAINT ledger_entries_refund_line_id_foreign
                        FOREIGN KEY (refund_line_id) REFERENCES refund_lines (id)
                        ON DELETE RESTRICT");

        // Step 5: UNIQUE(refund_line_id) — exactly one debit per refund_line.
        //         MySQL treats multiple NULLs as distinct, so credits (refund_line_id IS NULL)
        //         are not affected.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unique('refund_line_id', 'ledger_refund_line_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_refund_line_unique');
            $table->dropForeign(['refund_line_id']);
        });
        DB::statement("ALTER TABLE ledger_entries
                        ADD CONSTRAINT ledger_entries_refund_line_id_foreign
                        FOREIGN KEY (refund_line_id) REFERENCES refund_lines (id)
                        ON DELETE SET NULL");
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
