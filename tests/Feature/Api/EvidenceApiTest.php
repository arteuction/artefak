<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Evidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for admin evidence endpoints:
 *   GET  /api/v1/evidence
 *   GET  /api/v1/evidence/{evidence}
 *   POST /api/v1/evidence/{evidence}/verify
 */
final class EvidenceApiTest extends TestCase
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

    private function evidence(string $status = 'pending', string $type = 'ownership_doc'): Evidence
    {
        return Evidence::create([
            'subject_type'        => 'App\\Models\\Artwork',
            'subject_id'          => 1,
            'type'                => $type,
            'document_path'       => 'evidence/' . uniqid() . '.pdf',
            'verification_status' => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_evidence(): void
    {
        $this->getJson('/api/v1/evidence')->assertStatus(401);
    }

    public function test_buyer_cannot_list_evidence(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/evidence')
            ->assertStatus(403);
    }

    public function test_admin_can_list_evidence(): void
    {
        $this->evidence();
        $this->evidence();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/evidence')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_index_can_filter_by_verification_status(): void
    {
        $this->evidence('pending');
        $this->evidence('verified');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/evidence?verification_status=pending')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('pending', $response->json('data.0.verification_status'));
    }

    public function test_index_can_filter_by_type(): void
    {
        $this->evidence('pending', 'ownership_doc');
        $this->evidence('pending', 'provenance_certificate');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/evidence?type=provenance_certificate')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_index_returns_empty_when_no_records(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/evidence')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_show_evidence(): void
    {
        $ev = $this->evidence();

        $this->getJson("/api/v1/evidence/{$ev->id}")->assertStatus(401);
    }

    public function test_admin_can_show_evidence(): void
    {
        $ev = $this->evidence('pending', 'ownership_doc');

        $response = $this->actingAs($this->admin())
            ->getJson("/api/v1/evidence/{$ev->id}")
            ->assertOk();

        $this->assertSame($ev->id, $response->json('id'));
        $this->assertSame('ownership_doc', $response->json('type'));
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/evidence/99999999')
            ->assertStatus(404);
    }

    // ── verify ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_verify_evidence(): void
    {
        $ev = $this->evidence();

        $this->postJson("/api/v1/evidence/{$ev->id}/verify", ['status' => 'verified'])
            ->assertStatus(401);
    }

    public function test_buyer_cannot_verify_evidence(): void
    {
        $ev = $this->evidence();

        $this->actingAs($this->buyer())
            ->postJson("/api/v1/evidence/{$ev->id}/verify", ['status' => 'verified'])
            ->assertStatus(403);
    }

    public function test_admin_can_verify_pending_evidence(): void
    {
        $ev = $this->evidence('pending');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/evidence/{$ev->id}/verify", [
                'status' => 'verified',
                'notes'  => 'Document checked.',
            ])
            ->assertOk();

        $this->assertSame('verified', $response->json('verification_status'));
        $this->assertNotNull($response->json('verified_at'));
    }

    public function test_admin_can_reject_pending_evidence(): void
    {
        $ev = $this->evidence('pending');

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/evidence/{$ev->id}/verify", ['status' => 'rejected'])
            ->assertOk();

        $this->assertSame('rejected', $response->json('verification_status'));
    }

    public function test_verify_rejects_already_verified_evidence(): void
    {
        $ev = $this->evidence('verified');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/evidence/{$ev->id}/verify", ['status' => 'rejected'])
            ->assertStatus(422);
    }

    public function test_verify_validates_required_status_field(): void
    {
        $ev = $this->evidence('pending');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/evidence/{$ev->id}/verify", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_verify_validates_status_enum(): void
    {
        $ev = $this->evidence('pending');

        $this->actingAs($this->admin())
            ->postJson("/api/v1/evidence/{$ev->id}/verify", ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
