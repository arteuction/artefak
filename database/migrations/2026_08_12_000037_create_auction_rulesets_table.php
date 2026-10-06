<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_rulesets', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Bidding mechanics
            $table->unsignedBigInteger('default_bid_increment_cents')->default(1000); // €10
            $table->unsignedSmallInteger('anti_sniping_seconds')->default(120);       // extend if bid in last 2 min
            $table->unsignedSmallInteger('extension_seconds')->default(120);          // how long to extend

            // Feature flags
            $table->boolean('proxy_bid_enabled')->default(false);
            $table->boolean('reserve_enabled')->default(true);
            $table->boolean('counter_offer_enabled')->default(false);
            $table->boolean('seller_approval_required')->default(false);

            // Tie and max-bid policies
            $table->enum('tie_policy', ['first_wins', 'last_wins', 'admin_decides'])->default('first_wins');
            $table->enum('max_bid_policy', ['disabled', 'enabled', 'required'])->default('disabled');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_rulesets');
    }
};
