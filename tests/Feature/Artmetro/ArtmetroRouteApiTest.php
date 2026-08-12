<?php

declare(strict_types=1);

namespace Tests\Feature\Artmetro;

use App\Models\ArtmetroRoute;
use App\Models\ArtmetroRouteStop;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtmetroRouteApiTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $this->venue  = Venue::create(['name' => 'Metro Gallery', 'slug' => 'metro-gallery', 'type' => 'metro', 'geo_locality_id' => $locality->id]);
    }

    private function makeRoute(bool $published = true): ArtmetroRoute
    {
        static $n = 0;
        $n++;
        return ArtmetroRoute::create([
            'title'        => "Route {$n}",
            'slug'         => "route-{$n}",
            'difficulty'   => 'easy',
            'accessible'   => true,
            'is_published' => $published,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────

    public function test_index_returns_published_routes(): void
    {
        $this->makeRoute(published: true);
        $this->makeRoute(published: true);
        $this->makeRoute(published: false);

        $this->getJson('/api/routes')
             ->assertOk()
             ->assertJsonCount(2, 'routes');
    }

    public function test_index_includes_stops_count(): void
    {
        $route = $this->makeRoute();
        ArtmetroRouteStop::create(['route_id' => $route->id, 'venue_id' => $this->venue->id, 'sort_order' => 1]);

        $response = $this->getJson('/api/routes')->assertOk();

        $this->assertSame(1, $response->json('routes.0.stops_count'));
    }

    public function test_index_empty_when_no_published_routes(): void
    {
        $this->makeRoute(published: false);

        $this->getJson('/api/routes')
             ->assertOk()
             ->assertJsonCount(0, 'routes');
    }

    // ── show ──────────────────────────────────────────────────────

    public function test_show_returns_route_with_stops(): void
    {
        $route = $this->makeRoute();
        ArtmetroRouteStop::create(['route_id' => $route->id, 'venue_id' => $this->venue->id, 'sort_order' => 1, 'notes' => 'Main entrance']);

        $response = $this->getJson("/api/routes/{$route->id}")->assertOk();

        $this->assertSame($route->title, $response->json('route.title'));
        $this->assertCount(1, $response->json('route.stops'));
        $this->assertSame('Main entrance', $response->json('route.stops.0.notes'));
        $this->assertSame('Metro Gallery', $response->json('route.stops.0.venue.name'));
    }

    public function test_show_orders_stops_by_sort_order(): void
    {
        $route   = $this->makeRoute();
        $locality = GeoLocality::first();
        $venue2  = Venue::create(['name' => 'Second Stop', 'slug' => 'second-stop', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        ArtmetroRouteStop::create(['route_id' => $route->id, 'venue_id' => $venue2->id,       'sort_order' => 2]);
        ArtmetroRouteStop::create(['route_id' => $route->id, 'venue_id' => $this->venue->id,  'sort_order' => 1]);

        $response = $this->getJson("/api/routes/{$route->id}")->assertOk();

        $this->assertSame(1, $response->json('route.stops.0.sort_order'));
        $this->assertSame(2, $response->json('route.stops.1.sort_order'));
    }

    public function test_show_returns_404_for_unpublished_route(): void
    {
        $route = $this->makeRoute(published: false);

        $this->getJson("/api/routes/{$route->id}")->assertNotFound();
    }

    public function test_show_returns_404_for_missing_route(): void
    {
        $this->getJson('/api/routes/99999')->assertNotFound();
    }
}
