<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artmetro_exhibition_sdg', function (Blueprint $table) {
            $table->foreignId('exhibition_id')->constrained('exhibitions')->cascadeOnDelete();
            $table->unsignedTinyInteger('sdg_number'); // 1–17 UN SDGs
            $table->primary(['exhibition_id', 'sdg_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artmetro_exhibition_sdg');
    }
};
