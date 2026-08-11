<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic layer — Phase 2a (ArtMetro map).
 *
 * geo_regions: 28 Bulgarian administrative regions (области).
 * Source: NSI EKATTE via bg-geo.json (database/data/bg-geo.json).
 *
 * Note: table is named geo_regions (not regions) to avoid collision with
 * any future generic multi-country geo layer and to be unambiguous alongside
 * the existing financial `settlements` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_regions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 10)->unique();       // NSI region code
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_regions');
    }
};
