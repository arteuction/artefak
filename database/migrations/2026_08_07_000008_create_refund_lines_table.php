<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('refund_id')
                  ->constrained('refunds')
                  ->cascadeOnDelete();

            $table->foreignId('settlement_line_id')
                  ->constrained('settlement_lines')
                  ->restrictOnDelete();

            // Allocated portion of the refund for this recipient
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            // Mirrors settlement_lines.recipient_type for fast queries
            $table->enum('recipient_type', ['artist', 'fund', 'ops']);

            $table->enum('status', ['pending', 'reversed', 'failed'])->default('pending');

            $table->timestamps();

            // One refund line per settlement line per refund
            $table->unique(['refund_id', 'settlement_line_id'], 'refund_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_lines');
    }
};
