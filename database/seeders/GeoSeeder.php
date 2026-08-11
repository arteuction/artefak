<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports Bulgarian geography from database/data/bg-geo.json.
 *
 * Source: NSI EKATTE — 28 regions, 265 municipalities, 5 256 localities.
 * Run once on fresh install; safe to re-run (truncates first).
 */
class GeoSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/bg-geo.json');

        if (! file_exists($path)) {
            $this->command->error("bg-geo.json not found at {$path}");
            return;
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('geo_localities')->truncate();
        DB::table('geo_municipalities')->truncate();
        DB::table('geo_regions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $now = now();

        foreach ($data['regions'] as $r) {
            $regionId = (int) DB::table('geo_regions')->insertGetId([
                'name'       => $r['name'],
                'slug'       => Str::slug($r['name']),
                'code'       => $r['code'],
                'latitude'   => $r['lat']  ?? null,
                'longitude'  => $r['lng']  ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($r['municipalities'] ?? [] as $m) {
                $munId = (int) DB::table('geo_municipalities')->insertGetId([
                    'geo_region_id' => $regionId,
                    'name'          => $m['name'],
                    'slug'          => Str::slug($m['name'] . '-' . $r['code']),
                    'code'          => $m['code'],
                    'latitude'      => $m['lat']  ?? null,
                    'longitude'     => $m['lng']  ?? null,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);

                $rows = [];
                foreach ($m['settlements'] ?? [] as $s) {
                    $rows[] = [
                        'geo_municipality_id' => $munId,
                        'name'                => $s['name'],
                        'slug'                => Str::slug($s['name'] . '-' . $s['ekatte']),
                        'ekatte'              => $s['ekatte'],
                        'type'                => $s['type'] ?? 'village',
                        'latitude'            => $s['lat']  ?? null,
                        'longitude'           => $s['lng']  ?? null,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }

                if ($rows) {
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table('geo_localities')->insert($chunk);
                    }
                }
            }
        }

        $regions      = DB::table('geo_regions')->count();
        $municipalities = DB::table('geo_municipalities')->count();
        $localities   = DB::table('geo_localities')->count();

        $this->command->info("Geo seeded: {$regions} regions, {$municipalities} municipalities, {$localities} localities.");
    }
}
