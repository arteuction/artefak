<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only donation ledger — separate from Transaction and Impact ledgers.
        // Rows are NEVER updated after status reaches 'confirmed'.
        // Corrections are made via a new reversing row with type='reversal'.
        Schema::create('donations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('donation_recipient_id')
                  ->constrained('donation_recipients')
                  ->restrictOnDelete();

            // Donor — always a platform user (buyer at sale time)
            $table->foreignId('donor_id')
                  ->constrained('users')
                  ->restrictOnDelete();

            // Source of the donation (polymorphic by column pair)
            // One of these must be set; never more than one.
            $table->foreignId('art_lot_id')->nullable()
                  ->constrained('art_lots')->nullOnDelete();
            $table->foreignId('auction_item_id')->nullable()
                  ->constrained('auction_items')->nullOnDelete();
            $table->foreignId('sell_now_offer_id')->nullable()
                  ->constrained('sell_now_offers')->nullOnDelete();

            // Gross donated amount in cents
            $table->unsignedBigInteger('donated_cents');
            $table->char('currency', 3)->default('EUR');

            // Frozen ZKPO snapshot — never recomputed after confirmed
            $table->string('eligibility_basis', 60);  // snapshot of recipient.eligibility_basis
            $table->unsignedSmallInteger('deduction_bps'); // snapshot of recipient.deduction_bps
            $table->unsignedBigInteger('max_deductible_cents'); // donated_cents * deduction_bps / 10000

            $table->enum('type', ['donation', 'reversal'])->default('donation');

            $table->enum('status', [
                'pending',    // recorded but not yet transferred
                'confirmed',  // Stripe transfer complete
                'reversed',   // reversed by a reversal row
            ])->default('pending');

            // Optional reference to a reversed row (only set when type='reversal')
            $table->foreignId('reverses_donation_id')->nullable()
                  ->constrained('donations')->nullOnDelete();

            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();

            $table->index(['donation_recipient_id', 'status']);
            $table->index(['donor_id', 'status']);
            $table->index(['art_lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
