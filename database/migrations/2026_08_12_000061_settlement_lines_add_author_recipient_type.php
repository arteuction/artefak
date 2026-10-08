<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends settlement_lines.recipient_type to include 'author' and 'co_author'
 * which are used by CreateBookSettlement for the library 80/10/10 profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE settlement_lines MODIFY COLUMN recipient_type ENUM('artist','fund','ops','author','co_author') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE settlement_lines MODIFY COLUMN recipient_type ENUM('artist','fund','ops') NOT NULL"
        );
    }
};
