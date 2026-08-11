<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // artist/owner
            $table->string('title');
            $table->string('slug')->unique();
            $table->enum('medium', ['painting', 'sculpture', 'photography', 'digital', 'nft', 'mixed', 'other'])
                  ->default('painting');
            $table->string('dimensions')->nullable();       // e.g. "80x60 cm"
            $table->year('year_created')->nullable();
            $table->text('description')->nullable();
            $table->string('provenance')->nullable();
            $table->boolean('is_original')->default(true);
            $table->unsignedSmallInteger('edition_number')->nullable();
            $table->unsignedSmallInteger('edition_total')->nullable();
            $table->string('ar_model_url', 500)->nullable(); // USDZ / glTF for AR preview
            $table->enum('status', ['draft', 'listed', 'in_auction', 'sold', 'archived'])
                  ->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artworks');
    }
};
