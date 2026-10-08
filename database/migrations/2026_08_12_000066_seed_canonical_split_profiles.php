<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the two canonical split profiles from the domain SplitProfile value
 * object into the database as version 1 of each.
 *
 * This migration is idempotent via insertOrIgnore.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('split_profiles')->insertOrIgnore([
            [
                'profile_key'    => 'social_pilot_45_45_10',
                'version'        => 1,
                'artist_bps'     => 4500,
                'fund_bps'       => 4500,
                'ops_bps'        => 1000,
                'description'    => 'ArteUction phygital auction — equal artist/fund split. Artist 45%, Impact Fund 45%, Operations 10%.',
                'status'         => 'active',
                'effective_from' => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'profile_key'    => 'library_80_10_10',
                'version'        => 1,
                'artist_bps'     => 8000,
                'fund_bps'       => 1000,
                'ops_bps'        => 1000,
                'description'    => 'Artefak digital library — author-first marketplace. Author 80%, SDG Fund 10%, Operations 10%.',
                'status'         => 'active',
                'effective_from' => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('split_profiles')
            ->whereIn('profile_key', ['social_pilot_45_45_10', 'library_80_10_10'])
            ->where('version', 1)
            ->delete();
    }
};
