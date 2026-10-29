<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_categories', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            // Nested categories (one level: parent → children)
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('book_categories')
                  ->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        Schema::create('book_category_book', function (Blueprint $table) {
            $table->foreignId('book_id')
                  ->constrained('books')
                  ->cascadeOnDelete();

            $table->foreignId('book_category_id')
                  ->constrained('book_categories')
                  ->cascadeOnDelete();

            $table->primary(['book_id', 'book_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_category_book');
        Schema::dropIfExists('book_categories');
    }
};
