<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid Sale mode: auction runs until ends_at; Buy Now available at a fixed
 * price until the first bid is placed or the artwork is purchased outright.
 *
 * Adds:
 *   sale_mode = 'hybrid' (extend ENUM)
 *   buy_now_price_cents — the instant-purchase price (used in hybrid + sell_now)
 *   buy_now_expires_at  — optional: Buy Now disabled after this timestamp
 *                         (e.g. "Buy Now disappears once first bid is placed")
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE art_lots MODIFY COLUMN sale_mode ENUM('auction','sell_now','gallery','private','hybrid') NOT NULL DEFAULT 'auction'");

        Schema::table('art_lots', function (Blueprint $table): void {
            if (! Schema::hasColumn('art_lots', 'buy_now_price_cents')) {
                $table->unsignedBigInteger('buy_now_price_cents')->nullable()->after('starting_bid_cents');
            }
            if (! Schema::hasColumn('art_lots', 'buy_now_expires_at')) {
                $table->timestamp('buy_now_expires_at')->nullable()->after('buy_now_price_cents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('art_lots', function (Blueprint $table): void {
            $table->dropColumn(['buy_now_price_cents', 'buy_now_expires_at']);
        });

        DB::statement("ALTER TABLE art_lots MODIFY COLUMN sale_mode ENUM('auction','sell_now','gallery','private') NOT NULL DEFAULT 'auction'");
    }
};
