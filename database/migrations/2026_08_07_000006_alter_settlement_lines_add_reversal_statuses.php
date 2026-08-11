<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'reversal_pending' (reversal order created, not yet executed)
        // and 'canceled' (line never transferred, voided by refund).
        DB::statement("
            ALTER TABLE settlement_lines
            MODIFY COLUMN status
                ENUM('pending','transferred','reversal_pending','reversed','canceled')
                NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // Remap values not present in the original enum before shrinking it.
        DB::statement("UPDATE settlement_lines SET status = 'pending'    WHERE status = 'reversal_pending'");
        DB::statement("UPDATE settlement_lines SET status = 'pending'    WHERE status = 'canceled'");

        DB::statement("
            ALTER TABLE settlement_lines
            MODIFY COLUMN status
                ENUM('pending','transferred','reversed')
                NOT NULL DEFAULT 'pending'
        ");
    }
};
