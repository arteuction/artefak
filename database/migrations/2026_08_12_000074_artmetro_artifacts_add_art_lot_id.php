<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ArtMetro bridge — link an artifact QR label to its current active ArtLot.
 *
 * When a visitor scans the QR, the API can surface:
 *   - Artwork information (from sellable_id / Artwork model)
 *   - Current sale status (from art_lot_id → ArtLot → AuctionItem / SellNowOffer)
 *   - Live bid amount, next bid, time remaining (via WebSocket / polling)
 *
 * Nullable because a physical label may exist before the work is listed for sale.
 * Automatically cleared (nullOnDelete) if the lot is deleted (rare edge case).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artmetro_artifacts', function (Blueprint $table): void {
            $table->foreignId('art_lot_id')
                ->nullable()
                ->after('sellable_id')
                ->constrained('art_lots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('artmetro_artifacts', function (Blueprint $table): void {
            $table->dropForeign(['art_lot_id']);
            $table->dropColumn('art_lot_id');
        });
    }
};
