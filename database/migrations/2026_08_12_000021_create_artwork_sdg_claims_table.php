<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_sdg_claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')->constrained('artworks')->cascadeOnDelete();
            $table->unsignedTinyInteger('sdg_number'); // 1–17

            // Claim content — why this artwork relates to this SDG
            $table->text('rationale');
            $table->text('evidence')->nullable(); // URLs, filenames, or descriptions of evidence

            // Moderation
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // One claim per artwork+SDG pair; re-submission replaces via upsert
            $table->unique(['artwork_id', 'sdg_number']);
            $table->index(['artwork_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_sdg_claims');
    }
};
