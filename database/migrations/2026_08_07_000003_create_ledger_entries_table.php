<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only fund ledger — rows are NEVER updated or deleted.
        // Balance = SUM(credit cents) - SUM(debit cents).
        // Corrections are made via a new reversing row, not edits.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('settlement_id')
                  ->constrained('settlements')
                  ->restrictOnDelete(); // do not cascade — history must survive

            $table->foreignId('settlement_line_id')
                  ->nullable()
                  ->constrained('settlement_lines')
                  ->nullOnDelete();

            $table->enum('type', ['credit', 'debit']);

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            // Human-readable reason: 'Sale split', 'Refund reversal', etc.
            $table->string('note', 200)->nullable();

            $table->timestamps();

            // One credit and one debit per settlement line (idempotency)
            $table->unique(['settlement_line_id', 'type'], 'ledger_line_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
