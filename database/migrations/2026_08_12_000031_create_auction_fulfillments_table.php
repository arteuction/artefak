<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_fulfillments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('auction_item_id')
                  ->unique() // one fulfillment record per lot
                  ->constrained('auction_items')
                  ->cascadeOnDelete();

            $table->foreignId('winner_user_id')
                  ->constrained('users')
                  ->restrictOnDelete();

            // Shipping address (captured at fulfillment creation time)
            $table->string('shipping_name', 200);
            $table->string('shipping_line1', 255);
            $table->string('shipping_line2', 255)->nullable();
            $table->string('shipping_city', 100);
            $table->string('shipping_postal_code', 20);
            $table->char('shipping_country', 2); // ISO 3166-1 alpha-2

            // Carrier info (filled when shipped)
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 200)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_fulfillments');
    }
};
