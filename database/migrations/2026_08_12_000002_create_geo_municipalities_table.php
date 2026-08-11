<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic layer — Phase 2a (ArtMetro map).
 *
 * geo_municipalities: 265 Bulgarian municipalities (общини).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_municipalities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('geo_region_id')->constrained('geo_regions')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 10)->unique();       // NSI municipality code
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index('geo_region_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_municipalities');
    }
};
