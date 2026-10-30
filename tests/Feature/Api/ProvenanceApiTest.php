<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for provenance (W3C PROV-O) endpoint:
 *   GET /api/v1/art-lots/{artLot}/provenance
 */
final class ProvenanceApiTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function artLot(User $artist): ArtLot
    {
        $artwork = Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Provenance Art ' . uniqid(),
            'slug'        => 'prov-art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);

        return ArtLot::create([
            'artwork_id'  => $artwork->id,
            'owner_id'    => $artist->id,
            'slug'        => 'lot-' . uniqid(),
            'status'      => 'active',
            'asking_price_cents' => 100000,
            'currency'    => 'EUR',
        ]);
    }

    public function test_provenance_is_public_unauthenticated(): void
    {
        $artist = $this->artist();
        $lot    = $this->artLot($artist);

        $this->getJson("/api/v1/art-lots/{$lot->slug}/provenance")
            ->assertOk();
    }

    public function test_provenance_returns_json_ld_content_type(): void
    {
        $artist = $this->artist();
        $lot    = $this->artLot($artist);

        $response = $this->getJson("/api/v1/art-lots/{$lot->slug}/provenance")
            ->assertOk();

        $this->assertStringContainsString(
            'application/ld+json',
            $response->headers->get('Content-Type') ?? ''
        );
    }

    public function test_provenance_response_has_context_and_graph_keys(): void
    {
        $artist = $this->artist();
        $lot    = $this->artLot($artist);

        $response = $this->getJson("/api/v1/art-lots/{$lot->slug}/provenance")
            ->assertOk();

        $this->assertArrayHasKey('@context', $response->json());
        $this->assertArrayHasKey('@graph', $response->json());
    }

    public function test_provenance_returns_404_for_unknown_lot(): void
    {
        $this->getJson('/api/v1/art-lots/nonexistent-slug-xyz/provenance')
            ->assertStatus(404);
    }
}
