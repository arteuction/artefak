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
            // Add art_lot_id — the commercial instance this auction slot refers to
            $table->foreignId('art_lot_id')
                  ->after('auction_id')
                  ->constrained('art_lots')
                  ->restrictOnDelete();
        });

        Schema::table('auction_items', function (Blueprint $table) {
            // Drop old unique constraint before dropping artwork_id
            $table->dropUnique(['auction_id', 'artwork_id']);
            // Drop FK on artwork_id before dropping the column (MariaDB requirement)
            $table->dropForeign(['artwork_id']);
            // Commercial terms moved to art_lots
            $table->dropColumn(['artwork_id', 'reserve_price_cents', 'starting_bid_cents', 'buy_now_price_cents']);
        });

        Schema::table('auction_items', function (Blueprint $table) {
            // Replace artwork_id unique with art_lot_id unique
            $table->unique(['auction_id', 'art_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            $table->dropUnique(['auction_id', 'art_lot_id']);
            $table->dropForeign(['art_lot_id']);
            $table->dropColumn('art_lot_id');

            $table->foreignId('artwork_id')->constrained('artworks')->restrictOnDelete();
            $table->unsignedBigInteger('reserve_price_cents')->default(0);
            $table->unsignedBigInteger('starting_bid_cents');
            $table->unsignedBigInteger('buy_now_price_cents')->nullable();
        });
    }
};
