<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track PaymentIntent authorization expiry on bids.
 *
 * Stripe cancels uncaptured PaymentIntents after 7 days by default.
 * A winning bid whose authorization has expired cannot be captured — this
 * must be detected and handled before settlement is attempted.
 *
 * authorization_expires_at: set when the PI is created (created_at + 7 days).
 *   NULL for bids created before this migration.
 *
 * payment_status:
 *   authorized     — PI created, not yet captured (default for accepted/won bids)
 *   authorization_expired — PI expired before capture; winner must re-authorize
 *   captured       — PI successfully captured (payment confirmed)
 *   canceled       — PI canceled (outbid, retracted, or failed)
 *   failed         — capture attempted but failed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table): void {
            $table->timestamp('authorization_expires_at')->nullable()->after('stripe_payment_intent_id');
            $table->enum('payment_status', [
                'authorized',
                'authorization_expired',
                'captured',
                'canceled',
                'failed',
            ])->nullable()->after('authorization_expires_at');

            $table->index('authorization_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table): void {
            $table->dropIndex(['authorization_expires_at']);
            $table->dropColumn(['authorization_expires_at', 'payment_status']);
        });
    }
};
