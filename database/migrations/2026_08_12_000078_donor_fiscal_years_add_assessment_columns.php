<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add annual profit-assessment columns to donor_fiscal_years.
 *
 * ЗКПО чл.31 ceilings are applied against positive accounting profit,
 * not the donation amount. These columns record the donor's declared profit
 * and the resulting recognized deductible once assessment is performed.
 *
 * assessment_status:
 *   pending      — no profit declared yet (default)
 *   assessed     — recognized_deductible_cents computed
 *   reviewed     — validated by operator / accountant
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_fiscal_years', function (Blueprint $table): void {
            // Donor's declared positive accounting profit for the fiscal year (in cents).
            // NULL until the donor or their accountant submits it.
            $table->unsignedBigInteger('positive_profit_cents')->nullable()->after('donation_count');

            // Profit-based statutory ceiling = positive_profit_cents × bps / 10000
            $table->unsignedBigInteger('profit_ceiling_cents')->nullable()->after('positive_profit_cents');

            // min(aggregate_donated_cents, profit_ceiling_cents) — the amount
            // recognizable as a donation expense under Art.31 for this basis.
            $table->unsignedBigInteger('recognized_deductible_cents')->nullable()->after('profit_ceiling_cents');

            // Assessment lifecycle
            $table->enum('assessment_status', ['pending', 'assessed', 'reviewed'])
                  ->default('pending')
                  ->after('recognized_deductible_cents');

            $table->timestamp('assessed_at')->nullable()->after('assessment_status');
            $table->timestamp('reviewed_at')->nullable()->after('assessed_at');
            $table->string('reviewed_by_note', 500)->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('donor_fiscal_years', function (Blueprint $table): void {
            $table->dropColumn([
                'positive_profit_cents',
                'profit_ceiling_cents',
                'recognized_deductible_cents',
                'assessment_status',
                'assessed_at',
                'reviewed_at',
                'reviewed_by_note',
            ]);
        });
    }
};
