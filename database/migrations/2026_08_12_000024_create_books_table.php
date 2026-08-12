<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            // Owner — the user who uploaded (primary author or publisher account)
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();

            // Bibliographic metadata
            $table->string('isbn', 20)->nullable()->index();
            $table->string('publisher', 200)->nullable();
            $table->string('language', 10)->nullable();     // ISO 639-1
            $table->string('edition', 50)->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->year('publication_year')->nullable();
            $table->string('country_origin', 60)->nullable();

            // Pricing — stored as integer cents; currency ISO 4217
            $table->unsignedInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->boolean('is_free')->default(false);

            // Preview quota — number of pages readable without purchase (0 = none)
            $table->unsignedSmallInteger('preview_pages')->default(0);

            // Moderation
            $table->enum('status', ['draft', 'pending_review', 'published', 'rejected', 'unpublished'])
                  ->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->boolean('is_featured')->default(false);

            // Current file version pointer (denormalised for fast access)
            $table->unsignedBigInteger('current_file_version')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_featured']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
