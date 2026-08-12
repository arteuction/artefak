<?php

declare(strict_types=1);

namespace Tests\Feature\Artmetro;

use App\Domain\Artmetro\TagExhibitionSdgs;
use App\Models\Exhibition;
use App\Models\GeoLocality;
use App\Models\GeoMunicipality;
use App\Models\GeoRegion;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagExhibitionSdgsTest extends TestCase
{
    use RefreshDatabase;

    private Exhibition $exhibition;

    protected function setUp(): void
    {
        parent::setUp();

        $region       = GeoRegion::create(['name' => 'Sofia', 'slug' => 'sofia', 'code' => 'SOF']);
        $municipality = GeoMunicipality::create(['geo_region_id' => $region->id, 'name' => 'Sofia', 'slug' => 'sofia-sof', 'code' => 'SOF01']);
        $locality     = GeoLocality::create(['geo_municipality_id' => $municipality->id, 'name' => 'Sofia', 'slug' => 'sofia-68134', 'ekatte' => '68134', 'type' => 'city']);
        $venue        = Venue::create(['name' => 'Gallery', 'slug' => 'gallery', 'type' => 'private', 'geo_locality_id' => $locality->id]);

        $this->exhibition = Exhibition::create([
            'title'     => 'Green Art',
            'slug'      => 'green-art',
            'venue_id'  => $venue->id,
            'starts_at' => now()->subDay(),
            'ends_at'   => now()->addWeek(),
        ]);
    }

    private function action(): TagExhibitionSdgs
    {
        return new TagExhibitionSdgs();
    }

    public function test_tags_an_exhibition_with_sdg_numbers(): void
    {
        $this->action()->execute($this->exhibition, [4, 11, 17]);

        $this->assertSame([4, 11, 17], $this->exhibition->sdgNumbers());
    }

    public function test_replaces_existing_tags_atomically(): void
    {
        $this->action()->execute($this->exhibition, [1, 2, 3]);
        $this->action()->execute($this->exhibition, [7, 13]);

        $this->assertSame([7, 13], $this->exhibition->sdgNumbers());
        $this->assertDatabaseCount('artmetro_exhibition_sdg', 2);
    }

    public function test_clears_tags_with_empty_array(): void
    {
        $this->action()->execute($this->exhibition, [5, 6]);
        $this->action()->execute($this->exhibition, []);

        $this->assertSame([], $this->exhibition->sdgNumbers());
        $this->assertDatabaseCount('artmetro_exhibition_sdg', 0);
    }

    public function test_deduplicates_sdg_numbers(): void
    {
        $this->action()->execute($this->exhibition, [3, 3, 5, 5]);

        $this->assertSame([3, 5], $this->exhibition->sdgNumbers());
        $this->assertDatabaseCount('artmetro_exhibition_sdg', 2);
    }

    public function test_ignores_invalid_sdg_numbers(): void
    {
        $this->action()->execute($this->exhibition, [0, 1, 17, 18, -1]);

        $this->assertSame([1, 17], $this->exhibition->sdgNumbers());
    }

    public function test_accepts_all_17_sdgs(): void
    {
        $all = range(1, 17);
        $this->action()->execute($this->exhibition, $all);

        $this->assertSame($all, $this->exhibition->sdgNumbers());
        $this->assertDatabaseCount('artmetro_exhibition_sdg', 17);
    }
}
