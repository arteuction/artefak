<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')
                  ->constrained('books')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // 1–5 stars
            $table->unsignedTinyInteger('rating');

            $table->string('headline', 200)->nullable();
            $table->text('body')->nullable();

            // Moderation — reviews are hidden until approved
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One review per user per book
            $table->unique(['book_id', 'user_id']);
            $table->index(['book_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_reviews');
    }
};
