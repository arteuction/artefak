<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-item reconciliation mismatches.
 *
 * Each row records a single discrepancy found during a reconciliation run.
 * Storing individual mismatches (vs just a total delta) lets the operations
 * team identify exactly which Stripe transaction caused the divergence and
 * trace it back to the internal ledger record.
 *
 * Check types:
 *   payment    — captured PaymentIntent not in settlements (or vice-versa)
 *   refund     — Stripe refund not in internal reversal ledger (or vice-versa)
 *   transfer   — completed transfer_outbox row not in Stripe transfers (or vice-versa)
 *   payout     — Stripe payout to connected account has no matching settlement_line
 *
 * Resolution lifecycle:
 *   open → investigating → resolved | suppressed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_mismatches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('reconciliation_run_id')
                  ->constrained('reconciliation_runs')
                  ->cascadeOnDelete();

            $table->enum('check_type', ['payment', 'refund', 'transfer', 'payout']);

            // The specific Stripe transaction that caused the discrepancy (nullable
            // for internal-only mismatches where there is no Stripe counterpart)
            $table->string('stripe_transaction_id', 100)->nullable()->index();

            // Internal counterpart — e.g. settlement.stripe_payment_intent_id or
            // transfer_outbox.id cast to string
            $table->string('internal_reference', 100)->nullable();

            // Amounts for audit (all in the settlement's currency)
            $table->bigInteger('stripe_amount_cents')->nullable();
            $table->bigInteger('internal_amount_cents')->nullable();
            $table->bigInteger('delta_cents');            // internal - stripe; signed

            $table->string('description', 500)->nullable();

            $table->enum('resolution_status', ['open', 'investigating', 'resolved', 'suppressed'])
                  ->default('open');

            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['reconciliation_run_id', 'check_type']);
            $table->index('resolution_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_mismatches');
    }
};
