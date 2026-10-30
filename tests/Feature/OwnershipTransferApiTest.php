<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET /api/v1/ownership-transfers — scoped to buyer/seller; admin sees all.
 */
final class OwnershipTransferApiTest extends TestCase
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

    private function transfer(User $from, User $to): OwnershipTransfer
    {
        return OwnershipTransfer::create([
            'from_user_id'        => $from->id,
            'to_user_id'          => $to->id,
            'transfer_price_cents' => 5000,
            'currency'            => 'EUR',
            'channel'             => 'sell_now',
            'transferred_at'      => now(),
        ]);
    }

    // ── unauthenticated ───────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_transfers(): void
    {
        $this->getJson('/api/v1/ownership-transfers')->assertStatus(401);
    }

    public function test_unauthenticated_cannot_view_transfer(): void
    {
        $a      = $this->buyer();
        $b      = $this->buyer();
        $transfer = $this->transfer($a, $b);

        $this->getJson("/api/v1/ownership-transfers/{$transfer->id}")->assertStatus(401);
    }

    // ── index: scope ──────────────────────────────────────────────────────────

    public function test_buyer_sees_only_own_transfers(): void
    {
        $alice = $this->buyer();
        $bob   = $this->buyer();
        $carol = $this->buyer();

        $this->transfer($alice, $bob);   // alice → bob: visible to both
        $this->transfer($carol, $alice); // carol → alice: visible to alice
        $this->transfer($carol, $bob);   // carol → bob: NOT visible to alice

        $response = $this->actingAs($alice)
            ->getJson('/api/v1/ownership-transfers')
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_admin_sees_all_transfers(): void
    {
        $a = $this->buyer();
        $b = $this->buyer();
        $c = $this->buyer();

        $this->transfer($a, $b);
        $this->transfer($b, $c);
        $this->transfer($c, $a);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/ownership-transfers')
            ->assertOk();

        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_buyer_with_no_transfers_sees_empty_list(): void
    {
        $a = $this->buyer();
        $b = $this->buyer();
        $c = $this->buyer();

        $this->transfer($a, $b); // unrelated

        $response = $this->actingAs($c)
            ->getJson('/api/v1/ownership-transfers')
            ->assertOk();

        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_index_can_filter_by_channel(): void
    {
        $a = $this->buyer();
        $b = $this->buyer();

        $this->transfer($a, $b); // channel = sell_now

        OwnershipTransfer::create([
            'from_user_id'        => $a->id,
            'to_user_id'          => $b->id,
            'transfer_price_cents' => 2000,
            'currency'            => 'EUR',
            'channel'             => 'auction',
            'transferred_at'      => now(),
        ]);

        $response = $this->actingAs($a)
            ->getJson('/api/v1/ownership-transfers?channel=auction')
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('auction', $response->json('data.data.0.channel'));
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_buyer_can_view_transfer_they_participated_in_as_sender(): void
    {
        $alice    = $this->buyer();
        $bob      = $this->buyer();
        $transfer = $this->transfer($alice, $bob);

        $this->actingAs($alice)
            ->getJson("/api/v1/ownership-transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $transfer->id);
    }

    public function test_buyer_can_view_transfer_they_participated_in_as_receiver(): void
    {
        $alice    = $this->buyer();
        $bob      = $this->buyer();
        $transfer = $this->transfer($alice, $bob);

        $this->actingAs($bob)
            ->getJson("/api/v1/ownership-transfers/{$transfer->id}")
            ->assertOk();
    }

    public function test_buyer_cannot_view_unrelated_transfer(): void
    {
        $alice    = $this->buyer();
        $bob      = $this->buyer();
        $carol    = $this->buyer();
        $transfer = $this->transfer($alice, $bob);

        $this->actingAs($carol)
            ->getJson("/api/v1/ownership-transfers/{$transfer->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_view_any_transfer(): void
    {
        $a        = $this->buyer();
        $b        = $this->buyer();
        $transfer = $this->transfer($a, $b);

        $this->actingAs($this->admin())
            ->getJson("/api/v1/ownership-transfers/{$transfer->id}")
            ->assertOk();
    }
}
