<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 38: Artwork PATCH, ArtLot PATCH.
 */
final class Phase38ApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeArtwork(): array
    {
        $owner = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $owner->id,
            'title'   => 'Phase38 Art ' . uniqid(),
            'slug'    => 'p38-art-' . uniqid(),
            'status'  => 'draft',
        ]);
        return [$owner, $artwork];
    }

    // ── Artwork PATCH ────────────────────────────────────────────────────────

    public function test_owner_can_update_artwork(): void
    {
        [$owner, $artwork] = $this->makeArtwork();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/artworks/{$artwork->slug}", [
                'title'      => 'Updated Title',
                'medium'     => 'sculpture',
                'year_created' => 2020,
            ])
            ->assertOk()
            ->assertJsonFragment(['title' => 'Updated Title', 'medium' => 'sculpture']);
    }

    public function test_non_owner_cannot_update_artwork(): void
    {
        [, $artwork] = $this->makeArtwork();
        $other = User::factory()->create(['role' => 'artist']);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/artworks/{$artwork->slug}", ['title' => 'Hack'])
            ->assertForbidden();
    }

    public function test_admin_can_update_any_artwork(): void
    {
        [, $artwork] = $this->makeArtwork();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/artworks/{$artwork->slug}", ['description' => 'Admin edited'])
            ->assertOk()
            ->assertJsonFragment(['description' => 'Admin edited']);
    }

    // ── ArtLot PATCH ─────────────────────────────────────────────────────────

    public function test_consignor_can_update_draft_art_lot(): void
    {
        [$owner, $artwork] = $this->makeArtwork();
        $lot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $owner->id,
            'sale_mode'    => 'auction',
            'currency'     => 'BGN',
            'status'       => 'draft',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/art-lots/{$lot->id}", [
                'starting_bid_cents'  => 5000,
                'reserve_price_cents' => 8000,
            ])
            ->assertOk()
            ->assertJsonFragment(['starting_bid_cents' => 5000]);
    }

    public function test_cannot_update_submitted_art_lot(): void
    {
        [$owner, $artwork] = $this->makeArtwork();
        $lot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $owner->id,
            'sale_mode'    => 'auction',
            'currency'     => 'BGN',
            'status'       => 'submitted',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/art-lots/{$lot->id}", ['starting_bid_cents' => 5000])
            ->assertUnprocessable();
    }

    public function test_non_owner_cannot_update_art_lot(): void
    {
        [$owner, $artwork] = $this->makeArtwork();
        $other = User::factory()->create(['role' => 'artist']);
        $lot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $owner->id,
            'sale_mode'    => 'auction',
            'currency'     => 'BGN',
            'status'       => 'draft',
        ]);

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/v1/art-lots/{$lot->id}", ['starting_bid_cents' => 5000])
            ->assertForbidden();
    }
}
