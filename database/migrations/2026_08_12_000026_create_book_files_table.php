<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            // Files are never replaced in place — each upload is a new version row
            $table->unsignedSmallInteger('version')->default(1);

            // Private storage — path is never exposed directly to clients
            $table->string('disk', 20)->default('s3');
            $table->string('path', 500);
            $table->string('filename', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');

            // SHA-256 of the file contents for integrity and provenance
            $table->char('sha256', 64)->index();

            // Purpose of this file
            $table->enum('type', ['full', 'preview', 'audio_preview', 'cover'])
                  ->default('full');

            // Immutable publication status — once published, stays published
            $table->enum('status', ['processing', 'published', 'rejected'])->default('processing');

            // Immutable — files are never updated, only superseded by new version
            $table->timestamp('created_at')->useCurrent();

            $table->index(['book_id', 'type', 'status']);
            $table->unique(['book_id', 'type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_files');
    }
};
