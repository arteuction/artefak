<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SdgClaimApiTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function artwork(User $artist): Artwork
    {
        static $n = 0;
        $n++;
        return Artwork::create([
            'user_id' => $artist->id,
            'title'   => "Artwork $n",
            'slug'    => "artwork-$n",
            'status'  => 'listed',
        ]);
    }

    public function test_owner_can_submit_sdg_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        $res = $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->id}/sdg-claims", [
                'sdg_number' => 4,
                'rationale'  => 'Promotes quality education through art',
            ]);

        $res->assertCreated()
            ->assertJsonPath('sdg_number', 4)
            ->assertJsonPath('status', 'pending');
    }

    public function test_resubmitting_same_sdg_resets_to_pending(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 4,
            'rationale'  => 'Old rationale',
            'status'     => 'approved',
        ]);

        $res = $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->id}/sdg-claims", [
                'sdg_number' => 4,
                'rationale'  => 'Updated rationale',
            ]);

        $res->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('rationale', 'Updated rationale');
    }

    public function test_non_owner_cannot_submit_claim(): void
    {
        $artist  = $this->artist();
        $other   = $this->artist();
        $artwork = $this->artwork($artist);

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$artwork->id}/sdg-claims", [
                'sdg_number' => 1,
                'rationale'  => 'Nope',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_approve_claim(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);
        $admin   = User::factory()->create(['role' => 'admin']);

        $claim = ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 7,
            'rationale'  => 'Clean energy',
            'status'     => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/artworks/{$artwork->id}/sdg-claims/{$claim->id}/review", [
                'decision'    => 'approved',
                'review_note' => 'Looks good',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('review_note', 'Looks good');
    }

    public function test_public_index_returns_only_approved_claims(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        ArtworkSdgClaim::create(['artwork_id' => $artwork->id, 'sdg_number' => 1, 'rationale' => 'R', 'status' => 'approved']);
        ArtworkSdgClaim::create(['artwork_id' => $artwork->id, 'sdg_number' => 2, 'rationale' => 'R', 'status' => 'pending']);

        $res = $this->getJson("/api/v1/artworks/{$artwork->id}/sdg-claims");

        $res->assertOk();
        $data = $res->json();
        $this->assertCount(1, $data);
        $this->assertEquals(1, $data[0]['sdg_number']);
    }

    public function test_owner_index_returns_all_claims(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artwork($artist);

        ArtworkSdgClaim::create(['artwork_id' => $artwork->id, 'sdg_number' => 1, 'rationale' => 'R', 'status' => 'approved']);
        ArtworkSdgClaim::create(['artwork_id' => $artwork->id, 'sdg_number' => 2, 'rationale' => 'R', 'status' => 'pending']);

        $res = $this->actingAs($artist)->getJson("/api/v1/artworks/{$artwork->id}/sdg-claims");

        $res->assertOk();
        $this->assertCount(2, $res->json());
    }
}
