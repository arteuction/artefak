<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibitions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('auction_id')->nullable()->index(); // FK added when auctions table exists
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['venue_id', 'starts_at', 'ends_at']);
            $table->index(['auction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibitions');
    }
};
