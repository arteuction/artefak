<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Dispute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for dispute endpoints:
 *   GET  /api/v1/disputes
 *   POST /api/v1/disputes
 *   GET  /api/v1/disputes/{dispute}
 *   POST /api/v1/disputes/{dispute}/resolve
 *   POST /api/v1/disputes/{dispute}/evidence
 */
final class DisputeApiTest extends TestCase
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

    private function dispute(User $opener, string $status = 'open'): Dispute
    {
        return Dispute::create([
            'opened_by'   => $opener->id,
            'type'        => 'condition_mismatch',
            'description' => 'Test dispute description.',
            'status'      => $status,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_disputes(): void
    {
        $this->getJson('/api/v1/disputes')->assertStatus(401);
    }

    public function test_buyer_sees_only_own_disputes(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();

        $this->dispute($buyer1);
        $this->dispute($buyer2);

        $response = $this->actingAs($buyer1)
            ->getJson('/api/v1/disputes')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_admin_sees_all_disputes(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();

        $this->dispute($buyer1);
        $this->dispute($buyer2);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/disputes')
            ->assertOk();

        $this->assertSame(2, $response->json('total'));
    }

    public function test_index_can_filter_by_status(): void
    {
        $buyer = $this->buyer();
        $this->dispute($buyer, 'open');
        $this->dispute($buyer, 'resolved');

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/disputes?status=open')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('open', $response->json('data.0.status'));
    }

    public function test_index_can_filter_by_type(): void
    {
        $buyer = $this->buyer();
        Dispute::create([
            'opened_by'   => $buyer->id,
            'type'        => 'condition_mismatch',
            'description' => 'A.',
            'status'      => 'open',
        ]);
        Dispute::create([
            'opened_by'   => $buyer->id,
            'type'        => 'payment_dispute',
            'description' => 'B.',
            'status'      => 'open',
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/disputes?type=payment_dispute')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_open_dispute(): void
    {
        $this->postJson('/api/v1/disputes', [
            'type'        => 'condition_mismatch',
            'description' => 'Something went wrong.',
        ])->assertStatus(401);
    }

    public function test_buyer_can_open_dispute(): void
    {
        $buyer = $this->buyer();

        Schema::disableForeignKeyConstraints();
        $lotId = DB::table('art_lots')->insertGetId([
            'artwork_id'   => 1,
            'consignor_id' => $buyer->id,
            'sale_mode'    => 'auction',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $response = $this->actingAs($buyer)
            ->postJson('/api/v1/disputes', [
                'type'        => 'condition_mismatch',
                'description' => 'Item was damaged on arrival.',
                'art_lot_id'  => $lotId,
            ])
            ->assertStatus(201);

        $this->assertSame('condition_mismatch', $response->json('type'));
        $this->assertSame('open', $response->json('status'));
        $this->assertSame($buyer->id, $response->json('opened_by'));
    }

    public function test_store_validates_required_type(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/disputes', ['description' => 'Missing type.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_validates_type_enum(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/disputes', [
                'type'        => 'invalid_type',
                'description' => 'Bad type.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_validates_required_description(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/disputes', ['type' => 'other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_resolve_dispute(): void
    {
        $buyer   = $this->buyer();
        $dispute = $this->dispute($buyer);

        $this->postJson("/api/v1/disputes/{$dispute->id}/resolve", [
            'outcome'    => 'resolved',
            'resolution' => 'Refund issued.',
        ])->assertStatus(401);
    }

    public function test_buyer_cannot_resolve_dispute(): void
    {
        $buyer   = $this->buyer();
        $dispute = $this->dispute($buyer);

        $this->actingAs($buyer)
            ->postJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'outcome'    => 'resolved',
                'resolution' => 'Refund issued.',
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_resolve_open_dispute(): void
    {
        $buyer   = $this->buyer();
        $dispute = $this->dispute($buyer);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'outcome'    => 'resolved',
                'resolution' => 'Full refund issued.',
            ])
            ->assertOk();

        $this->assertSame('resolved', $response->json('status'));
        $this->assertNotNull($response->json('resolved_at'));
    }

    public function test_admin_can_dismiss_dispute(): void
    {
        $buyer   = $this->buyer();
        $dispute = $this->dispute($buyer);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'outcome'    => 'dismissed',
                'resolution' => 'No evidence of damage found.',
            ])
            ->assertOk();

        $this->assertSame('dismissed', $response->json('status'));
    }

    public function test_resolve_validates_outcome_enum(): void
    {
        $buyer   = $this->buyer();
        $dispute = $this->dispute($buyer);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'outcome'    => 'cancelled',
                'resolution' => 'Something.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outcome']);
    }
}
