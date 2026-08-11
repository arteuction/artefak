<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artmetro_artifacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exhibition_id')->constrained('exhibitions')->restrictOnDelete();

            // Polymorphic sellable — Artwork, ExternalProduct, etc.
            $table->string('sellable_type')->nullable();
            $table->unsignedBigInteger('sellable_id')->nullable();
            $table->index(['sellable_type', 'sellable_id'], 'artmetro_artifacts_sellable_index');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('ar_model_url', 500)->nullable();

            // QR
            $table->string('qr_token', 64)->unique();
            $table->unsignedTinyInteger('qr_version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['exhibition_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artmetro_artifacts');
    }
};
