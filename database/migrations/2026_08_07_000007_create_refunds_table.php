<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // From Stripe refund object
            $table->string('stripe_refund_id')->unique();
            $table->string('stripe_charge_id')->nullable()->index();

            $table->foreignId('settlement_id')
                  ->constrained('settlements')
                  ->restrictOnDelete();

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            $table->boolean('is_partial')->default(false);

            // Stripe refund lifecycle: pending | succeeded | failed | canceled
            $table->enum('refund_status', ['pending', 'succeeded', 'failed', 'canceled'])
                  ->default('pending');

            // Internal reconciliation lifecycle
            $table->enum('reconciliation_status', [
                'not_required', 'pending', 'completed', 'manual_intervention',
            ])->default('pending');

            // Stripe-supplied reason: duplicate | fraudulent | requested_by_customer | expired_uncaptured_charge
            $table->string('reason', 60)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
