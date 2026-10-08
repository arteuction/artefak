<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add schema version so consumers can handle multiple payload shapes
        // as the domain evolves. Version 1 = all rows written before this migration.
        DB::statement("
            ALTER TABLE domain_events
            ADD COLUMN event_version TINYINT UNSIGNED NOT NULL DEFAULT 1
                AFTER event_type
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE domain_events DROP COLUMN event_version");
    }
};
