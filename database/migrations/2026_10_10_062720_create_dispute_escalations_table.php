<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_escalations', function (Blueprint $table): void {
            $table->id();

            // The dispute being escalated (FK to disputes table)
            $table->foreignId('dispute_id')
                ->constrained('disputes')
                ->cascadeOnDelete();

            // Who escalated and to whom
            $table->foreignId('escalated_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Escalation level: 1 = ops team, 2 = senior ops, 3 = board
            $table->unsignedTinyInteger('level')->default(1);

            $table->text('reason')->nullable();

            // Governance outcome once resolved
            $table->enum('outcome', ['pending', 'resolved', 'dismissed', 'referred_external'])
                ->default('pending');
            $table->text('outcome_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['dispute_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_escalations');
    }
};
