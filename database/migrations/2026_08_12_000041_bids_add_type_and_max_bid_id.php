<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->enum('bid_type', [
                'live', 'pre_bid', 'proxy', 'kiosk', 'mobile', 'admin_override',
            ])->default('live')->after('ip_address');

            // Nullable FK — set when bid_type = proxy
            $table->foreignId('max_bid_id')
                  ->nullable()
                  ->after('bid_type')
                  ->constrained('max_bids')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropForeign(['max_bid_id']);
            $table->dropColumn(['bid_type', 'max_bid_id']);
        });
    }
};
