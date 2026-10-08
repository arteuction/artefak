<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only impact ledger — third and final separate ledger.
        // Records ACTUAL impact events linked to approved SDG claims.
        // Rows are NEVER updated or deleted; corrections via reversal rows.
        Schema::create('impact_events', function (Blueprint $table) {
            $table->id();

            // SDG claim that this event fulfils (must be approved)
            $table->foreignId('artwork_sdg_claim_id')
                  ->constrained('artwork_sdg_claims')
                  ->restrictOnDelete();

            // SDG goal — denormalised from the claim for fast aggregation
            $table->unsignedTinyInteger('sdg_number'); // 1–17

            // What kind of impact this event represents
            $table->enum('metric', [
                'sale_amount_eur',       // monetary: artwork sold (art lot / auction)
                'donation_amount_eur',   // monetary: donation confirmed
                'audience_reach',        // cultural: artmetro scans / exhibition visitors
                'artworks_exhibited',    // cultural: count of works shown
                'artworks_sold',         // cultural: count of works transacted
                'books_sold',            // cultural: count of book purchases
                'donors_count',          // social: number of unique donors
            ]);

            // Numeric magnitude of the event (cents for monetary, count for others)
            $table->unsignedBigInteger('magnitude');
            $table->char('currency', 3)->default('EUR'); // meaningful only for monetary metrics

            $table->enum('type', ['event', 'reversal'])->default('event');

            // Polymorphic source — exactly one must be set
            $table->foreignId('art_lot_id')->nullable()
                  ->constrained('art_lots')->nullOnDelete();
            $table->foreignId('donation_id')->nullable()
                  ->constrained('donations')->nullOnDelete();
            $table->foreignId('auction_item_id')->nullable()
                  ->constrained('auction_items')->nullOnDelete();
            $table->foreignId('sell_now_offer_id')->nullable()
                  ->constrained('sell_now_offers')->nullOnDelete();

            // For reversal rows only
            $table->foreignId('reverses_impact_event_id')->nullable()
                  ->constrained('impact_events')->nullOnDelete();

            $table->string('idempotency_key', 100)->unique();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['sdg_number', 'metric']);
            $table->index(['artwork_sdg_claim_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_events');
    }
};
