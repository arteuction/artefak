<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link donations to a specific ImpactProject.
 *
 * Nullable: most donations are to a DonationRecipient (the legal org),
 * but a donor may earmark their gift for a specific project the org runs.
 * The link is informational — it does not change the tax calculation or
 * the legal recipient; it connects the Donation Ledger to the Impact Ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->foreignId('impact_project_id')
                ->nullable()
                ->after('donation_recipient_id')
                ->constrained('impact_projects')
                ->nullOnDelete();

            $table->index('impact_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->dropForeign(['impact_project_id']);
            $table->dropColumn('impact_project_id');
        });
    }
};
