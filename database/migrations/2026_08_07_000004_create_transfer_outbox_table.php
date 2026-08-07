<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional outbox for Stripe Connect transfers.
        // Row is inserted INSIDE the settlement DB transaction.
        // Worker picks rows with status=pending and dispatches via Stripe API AFTER commit.
        // Stripe idempotency key = stripe_idempotency_key (never reused across attempts).
        Schema::create('transfer_outbox', function (Blueprint $table) {
            $table->id();

            $table->foreignId('settlement_line_id')
                  ->constrained('settlement_lines')
                  ->restrictOnDelete(); // history must survive

            // Stripe Connect destination account
            $table->string('stripe_account_id');

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            // Idempotency key sent to Stripe — never reused
            $table->string('stripe_idempotency_key')->unique();

            $table->enum('status', ['pending', 'dispatched', 'failed'])->default('pending');

            // Attempt counter and error log for retries / dead-letter
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();

            // Filled on success
            $table->string('stripe_transfer_id')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->timestamps();

            // One outbox row per line per attempt (idempotency at line level)
            $table->unique(['settlement_line_id', 'attempt'], 'outbox_line_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_outbox');
    }
};
