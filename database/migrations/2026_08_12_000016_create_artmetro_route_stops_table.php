<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artmetro_route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('artmetro_routes')->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'venue_id']);
            $table->index(['route_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artmetro_route_stops');
    }
};
