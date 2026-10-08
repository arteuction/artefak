<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the JSON `partners` column on impact_projects with a proper
 * relational table.
 *
 * Rationale: a single organization may participate in many projects.
 * A gallery, hospital, or NPO cannot be queried across projects from JSON.
 *
 * Roles: 'lead' | 'co_funder' | 'beneficiary' | 'implementation' | 'other'
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impact_project_partners', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('impact_project_id');
            $table->string('organization_name', 200);
            $table->string('organization_type', 60)->nullable(); // 'ngo' | 'gallery' | 'hospital' | 'municipality' | 'corporate' | 'other'
            $table->string('role', 30);  // lead / co_funder / beneficiary / implementation / other
            $table->string('url', 500)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            // 'active' | 'ended' | 'pending'
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('impact_project_id');
            $table->index('organization_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_project_partners');
    }
};
