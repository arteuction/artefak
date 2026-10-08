<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collector Identity — Curated groupings of artworks.
 *
 * A Collection is a logical grouping, NOT an ownership record.
 * Ownership is tracked by OwnershipTransfer; a Collection is a
 * curated view that a collector creates to organise, present, or
 * share their holdings (or a wish-list, or a thematic group they
 * curate editorially).
 *
 * This separation preserves provenance integrity: artworks can be
 * added to / removed from a Collection without any financial or legal
 * implications.  The ownership ledger (ownership_transfers) is the
 * single source of truth for legal title.
 *
 * Visibility:
 *   private  — visible to owner only
 *   unlisted — shareable by link, not indexed
 *   public   — discoverable on ArtMetro and the catalog
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();
            $table->string('cover_image_url', 500)->nullable();

            // 'private' | 'unlisted' | 'public'
            $table->string('visibility', 20)->default('private');

            $table->timestamps();

            $table->index(['owner_id', 'visibility']);
        });

        // Pivot: which artworks are in a collection
        Schema::create('collection_artworks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('artwork_id')->constrained('artworks')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('note')->nullable(); // curator note for this artwork

            $table->timestamps();

            $table->unique(['collection_id', 'artwork_id'], 'collection_artworks_unique');
            $table->index('collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_artworks');
        Schema::dropIfExists('collections');
    }
};
