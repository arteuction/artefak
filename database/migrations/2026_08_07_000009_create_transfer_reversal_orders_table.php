<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox for Stripe transfer reversals.
        // Inserted inside the refund transaction, dispatched post-commit.
        // Mirrors the transfer_outbox pattern: pending → processing → reversed/failed.
        Schema::create('transfer_reversal_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('refund_line_id')
                  ->constrained('refund_lines')
                  ->restrictOnDelete();

            // The original transfer ID to reverse (from settlement_lines)
            $table->string('stripe_transfer_id');

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            // Idempotency key sent to Stripe reversals API
            $table->string('idempotency_key')->unique();

            $table->enum('status', ['pending', 'processing', 'reversed', 'failed'])
                  ->default('pending');

            $table->unsignedTinyInteger('attempt')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable();

            // Filled on success
            $table->string('stripe_reversal_id')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            // One reversal order per refund_line (enforces single-reversal-per-line-per-refund)
            $table->unique('refund_line_id', 'tro_refund_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_reversal_orders');
    }
};
