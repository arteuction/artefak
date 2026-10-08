<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArtLotApiTest extends TestCase
{
    use RefreshDatabase;

    private User    $artist;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist  = User::factory()->create(['role' => 'artist']);
        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Test Art',
            'slug'    => 'test-art-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_index_returns_active_lots(): void
    {
        ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->artist->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);
        ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->artist->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'draft',
            'currency'     => 'EUR',
        ]);

        $this->getJson('/api/v1/art-lots')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_lot(): void
    {
        $lot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->artist->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        $this->getJson("/api/v1/art-lots/{$lot->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $lot->id]);
    }

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/v1/art-lots', [])
            ->assertUnauthorized();
    }

    public function test_store_creates_lot_as_draft(): void
    {
        $this->actingAs($this->artist)
            ->postJson('/api/v1/art-lots', [
                'artwork_id' => $this->artwork->id,
                'sale_mode'  => 'sell_now',
                'currency'   => 'EUR',
            ])
            ->assertCreated()
            ->assertJsonFragment(['status' => 'draft', 'sale_mode' => 'sell_now']);
    }

    public function test_store_validates_artwork_exists(): void
    {
        $this->actingAs($this->artist)
            ->postJson('/api/v1/art-lots', [
                'artwork_id' => 99999,
                'sale_mode'  => 'sell_now',
                'currency'   => 'EUR',
            ])
            ->assertUnprocessable();
    }
}
