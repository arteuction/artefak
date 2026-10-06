<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condition_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')
                  ->constrained('artworks')
                  ->cascadeOnDelete();

            $table->foreignId('inspector_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->unsignedSmallInteger('version')->default(1);

            $table->enum('surface', ['excellent', 'minor_wear', 'visible_wear', 'damage'])
                  ->default('excellent');

            $table->enum('frame', ['original', 'replacement', 'none'])
                  ->default('none');

            $table->enum('signature_status', ['verified', 'present', 'not_present'])
                  ->default('not_present');

            $table->boolean('certificate_available')->default(false);

            $table->enum('provenance_status', ['complete', 'partial', 'unknown'])
                  ->default('unknown');

            $table->enum('restoration', ['none', 'documented', 'undocumented'])
                  ->default('none');

            $table->text('notes')->nullable();

            $table->enum('status', ['draft', 'final'])->default('draft');

            // When this report was valid (snapshot timestamp for the buyer)
            $table->timestamp('valid_at')->nullable();

            $table->timestamps();

            $table->unique(['artwork_id', 'version']);
            $table->index(['artwork_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condition_reports');
    }
};
