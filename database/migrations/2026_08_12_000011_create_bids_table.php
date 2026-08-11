<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // bidder
            $table->unsignedBigInteger('amount_cents');
            $table->enum('status', ['pending', 'accepted', 'outbid', 'retracted', 'won'])
                  ->default('pending');
            $table->string('stripe_payment_intent_id', 100)->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();

            $table->index(['auction_item_id', 'amount_cents']);
            $table->index(['user_id', 'status']);
        });

        // Close the circular FK: auction_items.winning_bid_id → bids.id
        Schema::table('auction_items', function (Blueprint $table) {
            $table->foreign('winning_bid_id')->references('id')->on('bids')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            $table->dropForeign(['winning_bid_id']);
        });

        Schema::dropIfExists('bids');
    }
};
