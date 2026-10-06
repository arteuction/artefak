<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->foreignId('ruleset_id')
                  ->nullable()
                  ->after('venue_id')
                  ->constrained('auction_rulesets')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['ruleset_id']);
            $table->dropColumn('ruleset_id');
        });
    }
};
