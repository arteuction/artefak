<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Add FK from exhibitions.auction_id → auctions.id using raw SQL so MySQL
        // reuses the existing exhibitions_auction_id_index (created in migration 006)
        // instead of letting Laravel generate a duplicate ADD INDEX statement.
        DB::statement('ALTER TABLE exhibitions ADD CONSTRAINT exhibitions_auction_id_foreign
            FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE exhibitions DROP FOREIGN KEY exhibitions_auction_id_foreign');

        Schema::dropIfExists('auctions');
    }
};
