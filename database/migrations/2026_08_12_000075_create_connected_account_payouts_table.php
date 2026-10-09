<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_account_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_payout_id', 64)->unique();
            $table->string('stripe_account_id', 64)->index();
            $table->string('stripe_event_id', 64)->index();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            // paid | failed | canceled
            $table->enum('status', ['paid', 'failed', 'canceled']);
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('arrival_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_account_payouts');
    }
};
