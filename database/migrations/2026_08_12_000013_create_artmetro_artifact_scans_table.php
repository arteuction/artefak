<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artmetro_artifact_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artifact_id')
                  ->constrained('artmetro_artifacts')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Marketing attribution
            $table->string('referrer', 500)->nullable();
            $table->string('campaign', 120)->nullable();
            $table->string('locale', 10)->nullable();

            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('scanned_at')->useCurrent();

            $table->index(['artifact_id', 'scanned_at']);
            $table->index(['campaign', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artmetro_artifact_scans');
    }
};
