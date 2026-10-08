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
            $table->foreignId('gallery_id')
                  ->nullable()
                  ->after('consignor_id')
                  ->constrained('galleries')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('art_lots', function (Blueprint $table) {
            $table->dropForeign(['gallery_id']);
            $table->dropColumn('gallery_id');
        });
    }
};
