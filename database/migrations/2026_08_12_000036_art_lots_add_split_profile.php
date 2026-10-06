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
            $table->string('split_profile_key', 64)
                  ->default('social_pilot_45_45_10')
                  ->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('art_lots', function (Blueprint $table) {
            $table->dropColumn('split_profile_key');
        });
    }
};
