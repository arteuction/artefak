<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')
                  ->constrained('artworks')
                  ->cascadeOnDelete();

            $table->enum('type', [
                'authenticity',
                'provenance',
                'condition',
                'ownership',
                'certificate',
                'exhibition_history',
                'restoration',
            ]);

            $table->string('issuer', 200)->nullable();
            $table->date('issued_at')->nullable();

            // Path to uploaded document (S3/local)
            $table->string('document_path', 500)->nullable();

            $table->enum('verification_status', ['pending', 'verified', 'rejected'])
                  ->default('pending');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['artwork_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_evidence');
    }
};
