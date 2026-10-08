<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sell_now_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('art_lot_id')->constrained('art_lots')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();

            // Gallery acting as intermediary (optional — null = direct artist sale)
            $table->foreignId('gallery_id')->nullable()->constrained('galleries')->nullOnDelete();

            // Price negotiation
            $table->unsignedBigInteger('offered_price_cents');   // buyer's opening offer
            $table->unsignedBigInteger('counter_price_cents')->nullable(); // seller/gallery counter
            $table->unsignedBigInteger('agreed_price_cents')->nullable();  // final agreed price
            $table->char('currency', 3)->default('EUR');

            $table->enum('status', [
                'submitted',    // buyer submitted offer
                'countered',    // seller/gallery countered
                'accepted',     // both sides agree (used for fixed-price too)
                'rejected',     // seller/gallery rejected
                'expired',      // timed out
                'paid',         // payment confirmed
                'delivered',    // artwork delivered to buyer
                'closed',       // final state
            ])->default('submitted');

            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['art_lot_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sell_now_offers');
    }
};
