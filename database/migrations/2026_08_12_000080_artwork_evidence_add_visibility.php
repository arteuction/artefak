<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add visibility classification to artwork_evidence.
 *
 * Evidence must not automatically become public simply because it is
 * attached to a public artwork. Three visibility levels:
 *
 *   public     — approved provenance summaries, public certificates.
 *                Returned by public artwork endpoints.
 *   restricted — ownership documents, private gallery agreements,
 *                unpublished evidence. Visible to artwork owner and admin.
 *   sensitive  — payment records, donation documentation, personal data,
 *                dispute evidence. Visible to admin only.
 *
 * Default is 'restricted' (safe default — documents are private until
 * an operator explicitly marks them public).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artwork_evidence', function (Blueprint $table): void {
            $table->enum('visibility', ['public', 'restricted', 'sensitive'])
                  ->default('restricted')
                  ->after('verification_status');

            $table->index(['artwork_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::table('artwork_evidence', function (Blueprint $table): void {
            $table->dropIndex(['artwork_id', 'visibility']);
            $table->dropColumn('visibility');
        });
    }
};
