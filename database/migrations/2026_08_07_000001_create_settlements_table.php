<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();

            // One settlement per payment attempt; event_id is audit-only (one event → many items)
            $table->string('stripe_payment_intent_id')->unique();
            $table->string('stripe_event_id')->index();

            // Auction / artwork reference (nullable until Auction module is ported)
            $table->unsignedBigInteger('auction_id')->nullable()->index();

            // Gross amount in cents
            $table->unsignedInteger('gross_cents');
            $table->char('currency', 3)->default('EUR');

            // Frozen split snapshot — never recomputed from live config
            $table->string('profile_key', 60);
            $table->unsignedSmallInteger('profile_version');
            $table->unsignedSmallInteger('artist_bps');
            $table->unsignedSmallInteger('fund_bps');
            $table->unsignedSmallInteger('ops_bps');

            // Computed parts in cents (artist+fund+ops = gross_cents exactly)
            $table->unsignedInteger('artist_cents');
            $table->unsignedInteger('fund_cents');
            $table->unsignedInteger('ops_cents');

            // Lifecycle
            $table->enum('status', ['pending', 'completed', 'refunded', 'partially_refunded'])
                  ->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
