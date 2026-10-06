<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            // NULL means: use auction.ruleset.default_bid_increment_cents
            $table->unsignedBigInteger('bid_increment_cents')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('bid_increment_cents')->nullable(false)->default(1000)->change();
        });
    }
};
