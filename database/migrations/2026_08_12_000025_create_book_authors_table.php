<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_authors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();

            // Share of the AUTHOR POOL (not of gross).
            // Domain rule: SUM(share_bps) per book MUST equal 10 000.
            // Enforced in domain, not DB — fractional splits require application logic.
            $table->unsignedSmallInteger('share_bps');

            // Display order on book page
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['book_id', 'author_id']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_authors');
    }
};
