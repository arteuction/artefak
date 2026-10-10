<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table): void {
            // SPDX / Creative Commons license identifier (e.g. "CC BY-NC 4.0", "All Rights Reserved")
            $table->string('license_spdx', 100)->nullable()->after('provenance');

            // Resale royalty in basis points (0–5000 = 0%–50%).
            // Null means no resale right declared; 0 means explicitly waived.
            $table->unsignedSmallInteger('resale_royalty_bps')->nullable()->after('license_spdx');

            // Free-form rights statement (ODRL Policy URI or plain text)
            $table->string('rights_statement', 512)->nullable()->after('resale_royalty_bps');
        });
    }

    public function down(): void
    {
        Schema::table('artworks', function (Blueprint $table): void {
            $table->dropColumn(['license_spdx', 'resale_royalty_bps', 'rights_statement']);
        });
    }
};
