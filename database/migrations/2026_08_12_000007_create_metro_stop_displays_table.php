<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metro_stop_displays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metro_stop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exhibition_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['metro_stop_id', 'exhibition_id']);
            $table->index(['exhibition_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metro_stop_displays');
    }
};
