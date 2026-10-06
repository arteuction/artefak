<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('art_lots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')
                  ->constrained('artworks')
                  ->restrictOnDelete();

            // Who consigned this work for sale (artist or gallery rep)
            $table->foreignId('consignor_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->enum('sale_mode', ['auction', 'sell_now', 'gallery', 'private'])
                  ->default('auction');

            // Lot lifecycle — distinct from auction_item operational status
            $table->enum('status', [
                'draft',
                'submitted',
                'verification',
                'approved',
                'catalogued',
                'scheduled',
                'active',
                'sold',
                'unsold',
                'archived',
            ])->default('draft');

            // Commercial terms — frozen at sale time
            $table->unsignedBigInteger('reserve_price_cents')->nullable();
            $table->unsignedBigInteger('starting_bid_cents')->nullable();
            $table->unsignedBigInteger('buy_now_price_cents')->nullable();
            $table->char('currency', 3)->default('EUR');

            // Expert valuation range (public or confidential)
            $table->unsignedBigInteger('estimate_low_cents')->nullable();
            $table->unsignedBigInteger('estimate_high_cents')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['artwork_id', 'status']);
            $table->index('sale_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('art_lots');
    }
};
