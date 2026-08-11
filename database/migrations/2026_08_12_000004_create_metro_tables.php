<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ArtMetro — Phase 2a: metro infrastructure.
 *
 * metro_lines  — M1 / M2 / M3 with brand colour.
 * metro_stops  — individual stations with coordinates.
 *               Optional link to geo_localities for address resolution.
 * metro_stop_lines — pivot: which stops belong to which lines and in what
 *                    sequence order (a stop can appear on multiple lines,
 *                    e.g. Serdika is on M1 and M2).
 *
 * Future: metro_stop_displays links stops to active Exhibitions for the
 * digital signage "gallery-ad at the stop" feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metro_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 5)->unique();        // M1 | M2 | M3
            $table->string('name');
            $table->string('color', 10)->default('#000000');
            $table->timestamps();
        });

        Schema::create('metro_stops', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();       // M1-01, M2-07, …
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->foreignId('geo_locality_id')
                ->nullable()
                ->constrained('geo_localities')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['latitude', 'longitude'], 'metro_stops_lat_lng_index');
        });

        Schema::create('metro_stop_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('metro_stop_id')->constrained('metro_stops')->cascadeOnDelete();
            $table->foreignId('metro_line_id')->constrained('metro_lines')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order'); // position on the line

            $table->unique(['metro_stop_id', 'metro_line_id']);
            $table->index(['metro_line_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metro_stop_lines');
        Schema::dropIfExists('metro_stops');
        Schema::dropIfExists('metro_lines');
    }
};
