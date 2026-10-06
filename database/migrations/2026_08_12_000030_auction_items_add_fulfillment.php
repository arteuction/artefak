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
            // When winner must complete payment; null until lot is closed
            $table->timestamp('payment_deadline')->nullable()->after('winning_bid_id');

            // Tracks post-sale lifecycle
            $table->enum('fulfillment_status', [
                'none',             // lot not sold
                'awaiting_payment', // sold, PI not yet captured
                'paid',             // PI captured, settlement created
                'preparing',        // seller is packaging
                'shipped',          // tracking number added
                'delivered',        // confirmed receipt
                'payment_failed',   // deadline passed without capture
                'returned',         // item sent back
            ])->default('none')->after('payment_deadline');

            $table->index('fulfillment_status');
        });
    }

    public function down(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn(['payment_deadline', 'fulfillment_status']);
        });
    }
};
