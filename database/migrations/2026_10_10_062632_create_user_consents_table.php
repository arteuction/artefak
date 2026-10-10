<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('governance_policy_id')
                ->constrained('governance_policies')
                ->cascadeOnDelete();

            // Timestamp of explicit consent
            $table->timestamp('consented_at');

            // IP address at consent time (GDPR audit requirement)
            $table->string('ip_address', 45)->nullable();

            // User-agent string (abbreviated, for audit)
            $table->string('user_agent', 512)->nullable();

            // Whether the user later withdrew consent (e.g. GDPR Art. 7(3))
            $table->timestamp('withdrawn_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'governance_policy_id']);
            $table->index(['user_id', 'consented_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
