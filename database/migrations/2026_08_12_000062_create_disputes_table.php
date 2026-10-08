<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispute registry.
 *
 * Types:
 *   condition_mismatch     — buyer: artwork differs from condition report
 *   damage_after_delivery  — seller: buyer claims damage caused post-delivery
 *   unauthorized_sale      — artist: sale was not authorised
 *   title_dispute          — owner: gallery had no authority to sell
 *   payment_dispute        — payment not received / charged back
 *   other                  — catch-all; requires description
 *
 * Status lifecycle:
 *   open → under_review → resolved / dismissed / escalated
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->id();

            // Classification
            $table->string('type', 40);
            $table->text('description');

            // Opener
            $table->unsignedBigInteger('opened_by');   // users.id

            // Subject — the commercial object the dispute is about
            // Exactly one of the following must be set
            $table->unsignedBigInteger('art_lot_id')->nullable();
            $table->unsignedBigInteger('auction_item_id')->nullable();
            $table->unsignedBigInteger('sell_now_offer_id')->nullable();
            $table->unsignedBigInteger('ownership_transfer_id')->nullable();

            // Operator assignment
            $table->unsignedBigInteger('assigned_to')->nullable();  // users.id

            // Lifecycle
            // 'open' | 'under_review' | 'resolved' | 'dismissed' | 'escalated'
            $table->string('status', 20)->default('open');
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();  // users.id

            $table->timestamps();

            $table->index('opened_by');
            $table->index('status');
            $table->index('art_lot_id');
            $table->index('auction_item_id');
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
