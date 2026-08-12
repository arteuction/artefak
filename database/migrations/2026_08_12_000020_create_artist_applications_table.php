<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artist_profile_id')->constrained('artist_profiles')->cascadeOnDelete();

            // Submission
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected'])
                  ->default('draft');

            // Document references — stored paths only, never blob or PII on-chain
            $table->string('id_document_path', 500)->nullable();
            $table->string('portfolio_url', 500)->nullable();
            $table->text('motivation')->nullable();

            // Review
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            // Versioning — re-submissions increment this
            $table->unsignedSmallInteger('version')->default(1);

            $table->timestamps();

            $table->index(['artist_profile_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_applications');
    }
};
