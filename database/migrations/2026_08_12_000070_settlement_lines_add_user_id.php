<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add user_id to settlement_lines for the Payout Dashboard.
 *
 * legal_entity_id is a nullable opaque ID that may refer to a User, a Gallery,
 * or an external org.  user_id is a typed FK that makes "my payouts" queries
 * fast and type-safe without joining through another layer.
 *
 * Nullable because ops and fund lines have no individual user recipient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('legal_entity_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['user_id', 'status'], 'settlement_lines_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
