<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();

            // Lifecycle
            $table->enum('status', [
                'pending', 'paid', 'partially_refunded', 'refunded', 'disputed', 'failed',
            ])->default('pending');

            // Price snapshot at the moment of purchase — immutable after creation
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('EUR');

            // Financial breakdown — all in the same currency
            $table->unsignedInteger('gross_cents');      // what Stripe charged
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('fee_cents')->default(0);   // Stripe processing fee if passed through
            $table->unsignedInteger('split_base_cents'); // gross - tax - fee = basis for 80/10/10

            // Split profile snapshot — frozen at purchase time, never re-resolved on refund
            $table->string('profile_key', 60);
            $table->unsignedSmallInteger('profile_version');
            $table->unsignedSmallInteger('author_bps');
            $table->unsignedSmallInteger('fund_bps');
            $table->unsignedSmallInteger('ops_bps');

            // Stripe identifiers — unique to prevent duplicate processing
            $table->string('stripe_checkout_session_id', 100)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 100)->nullable()->unique();

            // Idempotency — one purchase per buyer per book per checkout attempt
            $table->string('idempotency_key', 100)->unique();

            // Timestamps
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Refund tracking
            $table->unsignedInteger('refunded_cents')->default(0);

            $table->index(['buyer_id', 'book_id']);
            $table->index(['book_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_purchases');
    }
};
