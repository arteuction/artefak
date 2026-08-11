<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds Sofia Metro lines, stops, and their pivot from database/data/sofia-metro.json.
 * Safe to re-run (truncates first, FK checks disabled during truncate).
 */
class MetroSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/sofia-metro.json');

        if (! file_exists($path)) {
            $this->command->error("sofia-metro.json not found at {$path}");
            return;
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('metro_stop_lines')->truncate();
        DB::table('metro_stops')->truncate();
        DB::table('metro_lines')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $now = now();

        // Insert lines and collect code → id map
        $lineIds = [];
        foreach ($data['lines'] as $l) {
            $lineIds[$l['code']] = (int) DB::table('metro_lines')->insertGetId([
                'code'       => $l['code'],
                'name'       => $l['name'],
                'color'      => $l['color'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Insert stops and pivot rows
        foreach ($data['stops'] as $s) {
            $stopId = (int) DB::table('metro_stops')->insertGetId([
                'code'        => $s['code'],
                'name'        => $s['name'],
                'latitude'    => $s['lat'],
                'longitude'   => $s['lng'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            foreach ($s['lines'] as $entry) {
                $lineCode = $entry['code'] ?? ($entry['line'] ?? $entry);
                $sortOrder = $entry['sort'] ?? ($entry['sort_order'] ?? 0);

                if (! isset($lineIds[$lineCode])) {
                    $this->command->warn("Unknown line code '{$lineCode}' for stop {$s['code']} — skipped.");
                    continue;
                }

                DB::table('metro_stop_lines')->insert([
                    'metro_stop_id' => $stopId,
                    'metro_line_id' => $lineIds[$lineCode],
                    'sort_order'    => $sortOrder,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        $lines = DB::table('metro_lines')->count();
        $stops = DB::table('metro_stops')->count();
        $pivots = DB::table('metro_stop_lines')->count();

        $this->command->info("Metro seeded: {$lines} lines, {$stops} stops, {$pivots} stop-line entries.");
    }
}
