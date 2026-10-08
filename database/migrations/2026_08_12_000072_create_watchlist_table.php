<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer Watchlist — a user marks artworks or auction items to follow.
 *
 * subject_type / subject_id: polymorphic — supports Artwork, AuctionItem, ArtLot.
 * One user can watch each subject at most once (unique constraint).
 * Removing a watch is a hard delete (no soft-delete needed — no audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();

            $table->unique(['user_id', 'subject_type', 'subject_id'], 'watchlist_user_subject_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist');
    }
};
