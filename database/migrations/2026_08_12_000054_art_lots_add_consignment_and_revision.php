<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('art_lots', function (Blueprint $table) {
            // Which consignment agreement covers this lot
            // Nullable: backwards-compatible; old lots keep consignor_id only
            $table->foreignId('consignment_id')
                  ->nullable()
                  ->after('gallery_id')
                  ->constrained('consignments')
                  ->nullOnDelete();

            // Which revision of the artwork metadata was in effect at listing time
            // Frozen at sale; never changes after lot is closed
            $table->foreignId('artwork_revision_id')
                  ->nullable()
                  ->after('consignment_id')
                  ->constrained('artwork_revisions')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('art_lots', function (Blueprint $table) {
            $table->dropForeign(['consignment_id']);
            $table->dropForeign(['artwork_revision_id']);
            $table->dropColumn(['consignment_id', 'artwork_revision_id']);
        });
    }
};
