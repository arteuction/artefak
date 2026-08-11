<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artmetro_routes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->enum('difficulty', ['easy', 'moderate', 'challenging'])->default('easy');
            $table->decimal('walking_distance_km', 5, 2)->nullable();
            $table->boolean('accessible')->default(false); // wheelchair accessible

            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artmetro_routes');
    }
};
