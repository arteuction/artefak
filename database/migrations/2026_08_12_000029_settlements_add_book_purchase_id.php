<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            // Nullable FK — auction settlements leave this null; book settlements leave auction_id null
            $table->unsignedBigInteger('book_purchase_id')->nullable()->after('auction_id');
            $table->index('book_purchase_id');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropIndex(['book_purchase_id']);
            $table->dropColumn('book_purchase_id');
        });
    }
};
