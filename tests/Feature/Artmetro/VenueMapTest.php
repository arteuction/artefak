<?php

declare(strict_types=1);

namespace Tests\Feature\ArtMetro;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 96 — Venue map GeoJSON endpoint.
 */
final class VenueMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_map_endpoint_returns_geojson_feature_collection(): void
    {
        $this->getJson('/api/v1/venues/map')
            ->assertOk()
            ->assertJsonFragment(['type' => 'FeatureCollection'])
            ->assertJsonStructure(['type', 'features']);
    }

    public function test_map_includes_venues_with_coordinates(): void
    {
        DB::table('venues')->insert([
            'name'            => 'Gallery Sofia',
            'slug'            => 'gallery-sofia',
            'geo_locality_id' => 1,
            'latitude'        => 42.6977,
            'longitude'       => 23.3219,
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $body = $this->getJson('/api/v1/venues/map')->assertOk()->json();

        $names = collect($body['features'])->pluck('properties.name');
        $this->assertTrue($names->contains('Gallery Sofia'));
    }

    public function test_map_excludes_venues_without_coordinates(): void
    {
        DB::table('venues')->insert([
            'name'            => 'No Coords',
            'slug'            => 'no-coords',
            'geo_locality_id' => 1,
            'latitude'        => null,
            'longitude'       => null,
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $body = $this->getJson('/api/v1/venues/map')->assertOk()->json();

        $names = collect($body['features'])->pluck('properties.name');
        $this->assertFalse($names->contains('No Coords'));
    }

    public function test_feature_geometry_is_geojson_point(): void
    {
        DB::table('venues')->insert([
            'name'            => 'Point Venue',
            'slug'            => 'point-venue',
            'geo_locality_id' => 1,
            'latitude'        => 43.2141,
            'longitude'       => 27.9147,
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $body = $this->getJson('/api/v1/venues/map')->assertOk()->json();

        $feature = collect($body['features'])->firstWhere('properties.name', 'Point Venue');
        $this->assertNotNull($feature);
        $this->assertSame('Point', $feature['geometry']['type']);
        $this->assertCount(2, $feature['geometry']['coordinates']);
    }
}
