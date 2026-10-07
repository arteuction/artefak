<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_item_id')->unique()->constrained()->cascadeOnDelete();

            // Snapshot at evaluation time (art_lot.reserve_price_cents may change)
            $table->unsignedBigInteger('reserve_price_cents');
            $table->unsignedBigInteger('highest_bid_cents');

            $table->enum('status', [
                'not_reached',
                'waived',       // seller accepts below-reserve price as-is
                'approved',     // seller explicitly approves (same outcome as waived)
                'counter_offered',
                'declined',     // seller declines — lot goes to 'passed'
            ])->default('not_reached');

            // Who acted on the reserve and when
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Counter-offer fields (set when status = counter_offered)
            $table->unsignedBigInteger('counter_offer_cents')->nullable();
            $table->timestamp('counter_offer_expires_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserves');
    }
};
