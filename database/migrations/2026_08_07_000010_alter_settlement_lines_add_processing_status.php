<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE settlement_lines MODIFY COLUMN status ENUM('pending','processing','transferred','reversal_pending','reversed','canceled') NOT NULL DEFAULT 'pending'");
    }
    public function down(): void {
        DB::statement("ALTER TABLE settlement_lines MODIFY COLUMN status ENUM('pending','transferred','reversal_pending','reversed','canceled') NOT NULL DEFAULT 'pending'");
    }
};
