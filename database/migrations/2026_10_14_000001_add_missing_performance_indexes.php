<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds indexes identified during the Phase 158 optimization audit.
 *
 * auction_items.art_lot_id       — the composite (auction_id, art_lot_id) unique index
 *                                  cannot satisfy queries filtering only on art_lot_id
 * settlements.status             — filtered by 'pending' in webhook handlers
 * bids compound index            — replaces single authorization_expires_at index;
 *                                  ExpireBidAuthorizations filters on both columns
 * ownership_transfers.transferred_at — used in ORDER BY for provenance queries
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_items', function (Blueprint $table): void {
            $table->index('art_lot_id', 'auction_items_art_lot_id_index');
        });

        Schema::table('settlements', function (Blueprint $table): void {
            $table->index('status', 'settlements_status_index');
        });

        Schema::table('bids', function (Blueprint $table): void {
            // Drop the single-column index added in migration 079 if it exists
            if (Schema::hasIndex('bids', 'bids_authorization_expires_at_index')) {
                $table->dropIndex('bids_authorization_expires_at_index');
            }
            $table->index(
                ['authorization_expires_at', 'payment_status'],
                'bids_auth_expires_payment_status_index'
            );
        });

        Schema::table('ownership_transfers', function (Blueprint $table): void {
            $table->index('transferred_at', 'ownership_transfers_transferred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('auction_items', function (Blueprint $table): void {
            $table->dropIndex('auction_items_art_lot_id_index');
        });

        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropIndex('settlements_status_index');
        });

        Schema::table('bids', function (Blueprint $table): void {
            $table->dropIndex('bids_auth_expires_payment_status_index');
            $table->index('authorization_expires_at', 'bids_authorization_expires_at_index');
        });

        Schema::table('ownership_transfers', function (Blueprint $table): void {
            $table->dropIndex('ownership_transfers_transferred_at_index');
        });
    }
};
