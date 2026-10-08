<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impact_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();
            $table->text('description')->nullable();

            // SDG alignment
            $table->tinyInteger('sdg_number')->unsigned()->nullable();

            // Financial targets
            $table->unsignedBigInteger('funding_target_cents')->nullable();
            $table->unsignedBigInteger('funding_actual_cents')->default(0);
            $table->string('currency', 3)->default('EUR');

            // Lifecycle
            // 'planned' | 'active' | 'completed' | 'cancelled'
            $table->string('status', 20)->default('planned');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Partners — stored as JSON array of {name, role, url?}
            $table->json('partners')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('sdg_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_projects');
    }
};
