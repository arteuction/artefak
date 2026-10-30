<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\ImpactEvent;
use App\Models\ImpactEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for cultural interoperability endpoints:
 *   GET /api/v1/artworks/{artwork}/edm          (Europeana EDM)
 *   GET /api/v1/artworks/{artwork}/linked-art   (Linked Art)
 *   GET /api/v1/artworks/{artwork}/qr           (QR code)
 *   GET /api/v1/impact-events/{event}/evidence  (ImpactEvidence index)
 *   POST /api/v1/impact-events/{event}/evidence (ImpactEvidence store)
 */
final class CulturalInteropApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function listedArtwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Cultural Art ' . uniqid(),
            'slug'        => 'cult-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
    }

    private function draftArtwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Draft Art ' . uniqid(),
            'slug'        => 'draft-' . uniqid(),
            'status'      => 'draft',
            'is_original' => true,
        ]);
    }

    private function impactEvent(): ImpactEvent
    {
        return ImpactEvent::create([
            'sdg_number'  => 13,
            'metric'      => 'trees_planted',
            'value'       => 5,
            'description' => 'Test event.',
        ]);
    }

    // ── Europeana EDM ─────────────────────────────────────────────────────────

    public function test_edm_endpoint_is_public(): void
    {
        $artwork = $this->listedArtwork($this->artist());
        $this->getJson("/api/v1/artworks/{$artwork->slug}/edm")->assertOk();
    }

    public function test_edm_returns_404_for_non_listed(): void
    {
        $artwork = $this->draftArtwork($this->artist());
        $this->getJson("/api/v1/artworks/{$artwork->slug}/edm")->assertStatus(404);
    }

    public function test_edm_response_has_context_and_graph(): void
    {
        $artwork  = $this->listedArtwork($this->artist());
        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/edm")->assertOk();

        $this->assertArrayHasKey('@context', $response->json());
        $this->assertArrayHasKey('@graph', $response->json());
    }

    public function test_edm_graph_contains_provided_cho(): void
    {
        $artwork  = $this->listedArtwork($this->artist());
        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/edm")->assertOk();

        $types = array_column($response->json('@graph'), '@type');
        $this->assertContains('edm:ProvidedCHO', $types);
    }

    // ── Linked Art ────────────────────────────────────────────────────────────

    public function test_linked_art_endpoint_is_public(): void
    {
        $artwork = $this->listedArtwork($this->artist());
        $this->getJson("/api/v1/artworks/{$artwork->slug}/linked-art")->assertOk();
    }

    public function test_linked_art_returns_404_for_non_listed(): void
    {
        $artwork = $this->draftArtwork($this->artist());
        $this->getJson("/api/v1/artworks/{$artwork->slug}/linked-art")->assertStatus(404);
    }

    public function test_linked_art_response_is_human_made_object(): void
    {
        $artwork  = $this->listedArtwork($this->artist());
        $response = $this->getJson("/api/v1/artworks/{$artwork->slug}/linked-art")->assertOk();

        $this->assertSame('HumanMadeObject', $response->json('type'));
        $this->assertArrayHasKey('@context', $response->json());
    }

    // ── QR Code ───────────────────────────────────────────────────────────────

    public function test_qr_code_returns_svg_for_listed_artwork(): void
    {
        $artwork  = $this->listedArtwork($this->artist());
        $response = $this->get("/api/v1/artworks/{$artwork->slug}/qr");

        $response->assertOk();
        $this->assertStringContainsString('image/svg+xml', $response->headers->get('Content-Type') ?? '');
    }

    public function test_qr_code_returns_404_for_non_listed(): void
    {
        $artwork = $this->draftArtwork($this->artist());
        $this->get("/api/v1/artworks/{$artwork->slug}/qr")->assertStatus(404);
    }

    // ── ImpactEvidence ────────────────────────────────────────────────────────

    public function test_impact_evidence_index_is_public(): void
    {
        $event = $this->impactEvent();
        $this->getJson("/api/v1/impact-events/{$event->id}/evidence")->assertOk();
    }

    public function test_impact_evidence_index_returns_data_key(): void
    {
        $event = $this->impactEvent();
        $response = $this->getJson("/api/v1/impact-events/{$event->id}/evidence")->assertOk();
        $this->assertArrayHasKey('data', $response->json());
    }

    public function test_unauthenticated_cannot_add_impact_evidence(): void
    {
        $event = $this->impactEvent();
        $this->postJson("/api/v1/impact-events/{$event->id}/evidence", [
            'type' => 'photo',
            'url'  => 'https://example.com/photo.jpg',
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_add_impact_evidence(): void
    {
        $event = $this->impactEvent();
        $this->actingAs($this->buyer())
            ->postJson("/api/v1/impact-events/{$event->id}/evidence", [
                'type' => 'photo',
                'url'  => 'https://example.com/photo.jpg',
            ])->assertStatus(403);
    }

    public function test_admin_can_add_impact_evidence(): void
    {
        $event    = $this->impactEvent();
        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/impact-events/{$event->id}/evidence", [
                'type'        => 'report',
                'url'         => 'https://example.com/report.pdf',
                'description' => 'Annual impact report.',
            ])
            ->assertStatus(201);

        $this->assertSame('report', $response->json('data.type'));
    }

    public function test_impact_evidence_validates_required_fields(): void
    {
        $event = $this->impactEvent();
        $this->actingAs($this->admin())
            ->postJson("/api/v1/impact-events/{$event->id}/evidence", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'url']);
    }

    public function test_impact_evidence_validates_type_enum(): void
    {
        $event = $this->impactEvent();
        $this->actingAs($this->admin())
            ->postJson("/api/v1/impact-events/{$event->id}/evidence", [
                'type' => 'video',
                'url'  => 'https://example.com/video.mp4',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }
}
