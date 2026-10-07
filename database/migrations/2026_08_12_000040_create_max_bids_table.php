<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('max_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // The ceiling the bidder is willing to pay — NEVER shown to other bidders
            $table->unsignedBigInteger('ceiling_cents');
            $table->char('currency', 3)->default('EUR');

            $table->enum('status', ['active', 'cancelled', 'exhausted'])->default('active');
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // One active max bid per bidder per item
            $table->unique(['auction_item_id', 'user_id', 'status']);
            $table->index(['auction_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('max_bids');
    }
};
