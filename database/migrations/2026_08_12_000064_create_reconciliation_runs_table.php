<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation runs — one row per day / period checked.
 *
 * Status:
 *   running → matched / mismatched / error
 *
 * A 'mismatched' run must be investigated; platform must NOT release
 * payouts until status is 'matched'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->id();

            $table->date('period_start');
            $table->date('period_end');

            // Internal ledger totals (from settlements + ledger_entries)
            $table->unsignedBigInteger('internal_gross_cents')->default(0);
            $table->unsignedBigInteger('internal_transfer_cents')->default(0);
            $table->unsignedInteger('internal_settlement_count')->default(0);

            // Stripe reported totals (from Stripe Balance / Payout API)
            $table->unsignedBigInteger('stripe_received_cents')->nullable();
            $table->unsignedBigInteger('stripe_transferred_cents')->nullable();

            // Deltas (internal − stripe; zero = match)
            $table->bigInteger('delta_received_cents')->nullable();
            $table->bigInteger('delta_transferred_cents')->nullable();

            // 'running' | 'matched' | 'mismatched' | 'error'
            $table->string('status', 20)->default('running');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('run_by')->nullable();  // users.id
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
