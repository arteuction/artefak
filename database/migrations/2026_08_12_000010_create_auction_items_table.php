<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artwork_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('lot_number');
            $table->unsignedBigInteger('reserve_price_cents')->default(0);
            $table->unsignedBigInteger('starting_bid_cents');
            $table->unsignedBigInteger('bid_increment_cents')->default(1000); // €10
            $table->unsignedBigInteger('buy_now_price_cents')->nullable();
            $table->enum('status', ['pending', 'open', 'sold', 'passed', 'canceled'])
                  ->default('pending');
            $table->unsignedBigInteger('winning_bid_id')->nullable()->index(); // FK added after bids table
            $table->timestamps();

            $table->unique(['auction_id', 'lot_number']);
            $table->unique(['auction_id', 'artwork_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_items');
    }
};
