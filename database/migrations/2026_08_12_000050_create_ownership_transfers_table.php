<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only provenance chain — one row per physical ownership change.
        // Part of the "verified transactional graph" that ArtMetro consumes downstream.
        Schema::create('ownership_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('art_lot_id')
                  ->constrained('art_lots')
                  ->restrictOnDelete(); // history must survive lot archiving

            // Seller at time of transfer
            $table->foreignId('from_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Buyer / new owner
            $table->foreignId('to_user_id')
                  ->constrained('users')
                  ->restrictOnDelete();

            // Source of the transfer — exactly one must be set
            $table->foreignId('auction_item_id')->nullable()
                  ->constrained('auction_items')->nullOnDelete();
            $table->foreignId('sell_now_offer_id')->nullable()
                  ->constrained('sell_now_offers')->nullOnDelete();

            // Price at which ownership changed (cents)
            $table->unsignedBigInteger('transfer_price_cents');
            $table->char('currency', 3)->default('EUR');

            $table->enum('channel', ['auction', 'sell_now', 'private', 'gift', 'inheritance'])
                  ->default('auction');

            // Physical handover confirmation
            $table->timestamp('transferred_at');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['art_lot_id']);
            $table->index(['to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownership_transfers');
    }
};
