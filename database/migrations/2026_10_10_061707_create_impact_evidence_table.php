<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impact_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('impact_event_id')
                ->constrained('impact_events')
                ->cascadeOnDelete();
            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Type of evidence
            $table->enum('type', ['report', 'photo', 'certification', 'url', 'other']);

            // URL to the evidence (S3 key, external URL, or IPFS CID)
            $table->string('url', 1024);

            // Optional human-readable description / caption
            $table->string('description', 512)->nullable();

            // Soft-deletable so evidence is never hard-deleted (integrity)
            $table->softDeletes();
            $table->timestamps();

            $table->index('impact_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_evidence');
    }
};
