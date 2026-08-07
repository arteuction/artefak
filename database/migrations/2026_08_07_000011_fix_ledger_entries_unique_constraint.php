<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("ledger_entries", function (Blueprint $table) {
            // MySQL uses ledger_line_type_unique as the backing index for the FK on
            // settlement_line_id. We must add a standalone index first, then drop the
            // composite unique so that multiple debit rows per line become possible
            // (required for cumulative partial refunds).
            $table->index("settlement_line_id", "ledger_settlement_line_idx");
        });

        Schema::table("ledger_entries", function (Blueprint $table) {
            $table->dropUnique("ledger_line_type_unique");

            $table->unsignedBigInteger("refund_line_id")->nullable()->after("settlement_line_id");
            $table->foreign("refund_line_id")->references("id")->on("refund_lines")->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table("ledger_entries", function (Blueprint $table) {
            $table->dropForeign(["refund_line_id"]);
            $table->dropColumn("refund_line_id");
            $table->unique(["settlement_line_id", "type"], "ledger_line_type_unique");
            $table->dropIndex("ledger_settlement_line_idx");
        });
    }
};
