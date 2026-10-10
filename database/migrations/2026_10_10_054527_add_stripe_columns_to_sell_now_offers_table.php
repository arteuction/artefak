<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sell_now_offers', function (Blueprint $table) {
            $table->string('stripe_checkout_session_id', 100)->nullable()->unique()->after('notes');
            $table->string('stripe_payment_intent_id', 100)->nullable()->unique()->after('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('sell_now_offers', function (Blueprint $table) {
            $table->dropColumn(['stripe_checkout_session_id', 'stripe_payment_intent_id']);
        });
    }
};
