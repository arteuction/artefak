<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic layer — Phase 2a (ArtMetro map).
 *
 * geo_localities: 5 256 Bulgarian settlements (селища) from NSI EKATTE.
 * Named "localities" to avoid collision with the financial `settlements` table.
 *
 * Composite index on (latitude, longitude) powers the bbox map queries and
 * the "galleries near metro stop" radius searches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_localities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('geo_municipality_id')->constrained('geo_municipalities')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('ekatte', 10)->unique();     // NSI EKATTE code
            $table->string('type', 20)->default('village'); // town | city | village
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index('geo_municipality_id');
            $table->index(['latitude', 'longitude'], 'geo_localities_lat_lng_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_localities');
    }
};
