<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Formal consignment agreement. Separates:
        //   owner_id    — who legally owns the artwork
        //   consignor_id — who signs the consignment (may differ from owner: estate, agent)
        //   gallery_id  — which gallery is conducting the sale (optional)
        //
        // ArtLot.consignor_id is kept for backwards compatibility but new lots
        // should reference consignment_id instead.
        Schema::create('consignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')
                  ->constrained('artworks')
                  ->restrictOnDelete();

            // Legal owner at the time of consignment
            $table->foreignId('owner_id')
                  ->constrained('users')
                  ->restrictOnDelete();

            // Who signs and is responsible for the consignment agreement
            // (may be the owner, their representative, or an estate executor)
            $table->foreignId('consignor_id')
                  ->constrained('users')
                  ->restrictOnDelete();

            // Optional gallery acting as sales intermediary
            $table->foreignId('gallery_id')
                  ->nullable()
                  ->constrained('galleries')
                  ->nullOnDelete();

            // Commission the consignor receives from the sale proceeds (basis points)
            // This is separate from the platform SplitProfile — it comes out of
            // the artist/consignor share before the platform split is applied.
            $table->unsignedSmallInteger('commission_bps')->default(0);

            $table->enum('status', [
                'draft',       // agreement not yet signed
                'active',      // live — lots can reference this
                'expired',     // end_date passed without a sale
                'terminated',  // cancelled by either party
                'completed',   // artwork sold, obligations fulfilled
            ])->default('draft');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();   // null = open-ended
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['artwork_id', 'status']);
            $table->index(['owner_id',   'status']);
            $table->index(['consignor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignments');
    }
};
