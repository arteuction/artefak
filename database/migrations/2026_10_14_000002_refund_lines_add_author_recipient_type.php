<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends refund_lines.recipient_type to include 'author' and 'co_author'
 * so that ProcessRefund can mirror settlement_lines.recipient_type correctly
 * when book-settlement lines (library 80/10/10 profile) are refunded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE refund_lines MODIFY COLUMN recipient_type ENUM('artist','fund','ops','author','co_author') NOT NULL"
        );
    }

    public function down(): void
    {
        $count = DB::table('refund_lines')
            ->whereIn('recipient_type', ['author', 'co_author'])
            ->count();

        if ($count > 0) {
            throw new \RuntimeException(
                "Cannot downgrade refund_lines.recipient_type: {$count} row(s) with 'author' or 'co_author' exist."
            );
        }

        DB::statement(
            "ALTER TABLE refund_lines MODIFY COLUMN recipient_type ENUM('artist','fund','ops') NOT NULL"
        );
    }
};
