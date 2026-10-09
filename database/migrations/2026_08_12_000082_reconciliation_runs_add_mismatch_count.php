<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_runs', function (Blueprint $table): void {
            $table->unsignedInteger('mismatch_count')->default(0)->after('delta_transferred_cents');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_runs', function (Blueprint $table): void {
            $table->dropColumn('mismatch_count');
        });
    }
};
