<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('settlement_id')
                  ->constrained('settlements')
                  ->cascadeOnDelete();

            // Recipient type: artist | fund | ops
            $table->enum('recipient_type', ['artist', 'fund', 'ops']);

            // Frozen legal entity snapshot — name/EIK must survive registry edits
            // (per SETTLEMENT_KERNEL_SPEC: FK alone is not enough)
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('entity_name', 200)->nullable();   // snapshot
            $table->string('entity_eik', 20)->nullable();     // snapshot
            $table->string('entity_role', 60)->nullable();    // artist|fund_custodian|operator

            // Stripe Connect — filled when transfer is dispatched
            $table->string('stripe_account_id')->nullable();
            $table->string('stripe_transfer_id')->nullable()->unique();

            // Amount in cents
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            // Weight within pool (for co-author / NGO sub-splits)
            $table->unsignedInteger('weight')->default(1);

            $table->enum('status', ['pending', 'transferred', 'reversed'])->default('pending');

            $table->timestamps();

            // One line per recipient_type+entity per settlement
            $table->unique(['settlement_id', 'recipient_type', 'legal_entity_id'],
                           'settlement_lines_unique_recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
    }
};
