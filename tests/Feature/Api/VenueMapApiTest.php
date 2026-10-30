<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for venue map endpoint:
 *   GET /api/v1/venues/map
 */
final class VenueMapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::disableForeignKeyConstraints();
        DB::table('geo_localities')->insertOrIgnore([
            'id'         => 1,
            'name'       => 'Test Locality',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
    }

    private function activeVenueWithCoords(float $lat = 48.8566, float $lng = 2.3522): Venue
    {
        return Venue::create([
            'geo_locality_id' => 1,
            'name'      => 'Venue ' . uniqid(),
            'slug'      => 'venue-' . uniqid(),
            'latitude'  => $lat,
            'longitude' => $lng,
            'is_active' => true,
        ]);
    }

    private function venueWithoutCoords(): Venue
    {
        return Venue::create([
            'geo_locality_id' => 1,
            'name'      => 'No Coords ' . uniqid(),
            'slug'      => 'no-coords-' . uniqid(),
            'latitude'  => null,
            'longitude' => null,
            'is_active' => true,
        ]);
    }

    private function inactiveVenue(): Venue
    {
        return Venue::create([
            'geo_locality_id' => 1,
            'name'      => 'Inactive ' . uniqid(),
            'slug'      => 'inactive-' . uniqid(),
            'latitude'  => 51.5,
            'longitude' => -0.1,
            'is_active' => false,
        ]);
    }

    public function test_map_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/venues/map')->assertOk();
    }

    public function test_map_returns_feature_collection(): void
    {
        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $this->assertSame('FeatureCollection', $response->json('type'));
        $this->assertArrayHasKey('features', $response->json());
    }

    public function test_map_includes_active_venues_with_coords(): void
    {
        $this->activeVenueWithCoords(48.8566, 2.3522);

        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $this->assertCount(1, $response->json('features'));
        $feature = $response->json('features.0');
        $this->assertSame('Feature', $feature['type']);
        $this->assertSame('Point', $feature['geometry']['type']);
    }

    public function test_map_excludes_venues_without_coordinates(): void
    {
        $this->activeVenueWithCoords();
        $this->venueWithoutCoords();

        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $this->assertCount(1, $response->json('features'));
    }

    public function test_map_excludes_inactive_venues(): void
    {
        $this->activeVenueWithCoords();
        $this->inactiveVenue();

        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $this->assertCount(1, $response->json('features'));
    }

    public function test_map_feature_properties_contain_required_keys(): void
    {
        $venue = $this->activeVenueWithCoords(52.5200, 13.4050);

        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $properties = $response->json('features.0.properties');
        $this->assertArrayHasKey('id', $properties);
        $this->assertArrayHasKey('name', $properties);
        $this->assertArrayHasKey('slug', $properties);
        $this->assertSame($venue->id, $properties['id']);
    }

    public function test_map_coordinates_are_longitude_latitude_order(): void
    {
        // GeoJSON spec: [longitude, latitude]
        $this->activeVenueWithCoords(48.8566, 2.3522);

        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $coords = $response->json('features.0.geometry.coordinates');
        $this->assertEqualsWithDelta(2.3522, $coords[0], 0.0001);
        $this->assertEqualsWithDelta(48.8566, $coords[1], 0.0001);
    }

    public function test_map_returns_empty_features_when_no_venues(): void
    {
        $response = $this->getJson('/api/v1/venues/map')->assertOk();

        $this->assertSame('FeatureCollection', $response->json('type'));
        $this->assertCount(0, $response->json('features'));
    }
}
