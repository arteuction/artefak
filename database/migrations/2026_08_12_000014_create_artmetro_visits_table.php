<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artmetro_visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artifact_id')
                  ->constrained('artmetro_artifacts')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Engagement depth
            $table->unsignedSmallInteger('time_on_page_seconds')->default(0);
            $table->boolean('video_watched')->default(false);
            $table->boolean('ar_launched')->default(false);
            $table->boolean('bid_clicked')->default(false);

            $table->string('session_id', 64)->nullable()->index();

            $table->timestamp('visited_at')->useCurrent();

            $table->index(['artifact_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artmetro_visits');
    }
};
