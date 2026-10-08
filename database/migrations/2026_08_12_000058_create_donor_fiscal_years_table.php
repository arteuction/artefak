<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('donor_id');
            $table->smallInteger('fiscal_year');
            $table->string('eligibility_basis', 60);

            // Running totals — append-only; never decremented
            $table->unsignedBigInteger('aggregate_donated_cents')->default(0);
            $table->unsignedBigInteger('aggregate_max_deductible_cents')->default(0);
            $table->unsignedInteger('donation_count')->default(0);

            // Documentation readiness for fiscal-year submission
            // 'incomplete' | 'ready' | 'submitted'
            $table->string('documentation_status', 20)->default('incomplete');
            $table->timestamp('documentation_submitted_at')->nullable();

            $table->timestamps();

            $table->unique(['donor_id', 'fiscal_year', 'eligibility_basis'], 'donor_fy_basis_unique');
            $table->index('donor_id');
            $table->index('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_fiscal_years');
    }
};
