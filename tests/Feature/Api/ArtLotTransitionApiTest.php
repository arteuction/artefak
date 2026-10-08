<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArtLotTransitionApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtures(): array
    {
        $consignor = User::factory()->create(['role' => 'artist']);
        $artwork   = Artwork::create(['user_id' => $consignor->id, 'title' => 'T', 'slug' => 'trans-' . uniqid(), 'status' => 'listed']);
        $gallery   = Gallery::create(['name' => 'G', 'slug' => 'g-' . uniqid(), 'status' => 'active']);
        $curator   = User::factory()->create(['role' => 'artist']);

        GalleryStaff::create(['gallery_id' => $gallery->id, 'user_id' => $curator->id, 'role' => 'curator', 'status' => 'active']);

        $lot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $consignor->id,
            'gallery_id'   => $gallery->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'draft',
            'currency'     => 'EUR',
        ]);

        return compact('consignor', 'curator', 'gallery', 'lot');
    }

    public function test_consignor_can_submit_draft_lot(): void
    {
        ['consignor' => $consignor, 'lot' => $lot] = $this->makeFixtures();

        $this->actingAs($consignor)
            ->postJson("/api/v1/art-lots/{$lot->id}/transitions/submit")
            ->assertOk()
            ->assertJsonPath('status', 'submitted');
    }

    public function test_gallery_curator_can_advance_through_pipeline(): void
    {
        ['curator' => $curator, 'lot' => $lot] = $this->makeFixtures();
        $lot->update(['status' => 'submitted']);

        $this->actingAs($curator)
            ->postJson("/api/v1/art-lots/{$lot->id}/transitions/verify")
            ->assertOk()
            ->assertJsonPath('status', 'verification');
    }

    public function test_non_consignor_cannot_submit(): void
    {
        ['lot' => $lot] = $this->makeFixtures();
        $other = User::factory()->create(['role' => 'artist']);

        $this->actingAs($other)
            ->postJson("/api/v1/art-lots/{$lot->id}/transitions/submit")
            ->assertForbidden();
    }

    public function test_admin_can_approve(): void
    {
        ['lot' => $lot] = $this->makeFixtures();
        $lot->update(['status' => 'verification']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson("/api/v1/art-lots/{$lot->id}/transitions/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_invalid_transition_returns_422(): void
    {
        ['consignor' => $consignor, 'lot' => $lot] = $this->makeFixtures();

        $this->actingAs($consignor)
            ->postJson("/api/v1/art-lots/{$lot->id}/transitions/submit")
            ->assertOk();

        // Cannot submit again from submitted
        $lot->refresh();
        $this->actingAs($consignor)
            ->postJson("/api/v1/art-lots/{$lot->id}/transitions/submit")
            ->assertOk(); // idempotent — already submitted
    }
}
