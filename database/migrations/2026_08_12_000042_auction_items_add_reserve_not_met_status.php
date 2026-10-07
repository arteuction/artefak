<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'open', 'reserve_not_met', 'sold', 'passed', 'canceled',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('auction_items', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'open', 'sold', 'passed', 'canceled',
            ])->default('pending')->change();
        });
    }
};
