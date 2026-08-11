<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'processing' (lease held during Stripe API call) and
        // 'canceled' (pending transfer voided by refund) to the status enum.
        // Also add processing_started_at for lease-timeout detection.
        DB::statement("
            ALTER TABLE transfer_outbox
            MODIFY COLUMN status
                ENUM('pending','processing','dispatched','canceled','failed')
                NOT NULL DEFAULT 'pending'
        ");
        DB::statement("
            ALTER TABLE transfer_outbox
            ADD COLUMN processing_started_at TIMESTAMP NULL DEFAULT NULL
                AFTER next_attempt_at
        ");
    }

    public function down(): void
    {
        // Remap values that do not exist in the original enum before shrinking it.
        DB::statement("UPDATE transfer_outbox SET status = 'pending'  WHERE status = 'processing'");
        DB::statement("UPDATE transfer_outbox SET status = 'failed'   WHERE status = 'canceled'");

        DB::statement("
            ALTER TABLE transfer_outbox
            DROP COLUMN processing_started_at
        ");
        DB::statement("
            ALTER TABLE transfer_outbox
            MODIFY COLUMN status
                ENUM('pending','dispatched','failed')
                NOT NULL DEFAULT 'pending'
        ");
    }
};
