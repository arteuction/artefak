<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['draft', 'published', 'live', 'closed', 'canceled'])
                  ->default('draft');
            $table->string('currency', 3)->default('EUR');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        // Now add the FK from exhibitions to auctions
        Schema::table('exhibitions', function (Blueprint $table) {
            $table->foreign('auction_id')->references('id')->on('auctions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exhibitions', function (Blueprint $table) {
            $table->dropForeign(['auction_id']);
        });

        Schema::dropIfExists('auctions');
    }
};
