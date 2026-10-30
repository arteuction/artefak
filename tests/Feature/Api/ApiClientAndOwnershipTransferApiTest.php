<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for API client management and ownership transfer endpoints:
 *   GET    /api/v1/api-clients
 *   POST   /api/v1/api-clients
 *   DELETE /api/v1/api-clients/{client}
 *   GET    /api/v1/ownership-transfers
 *   GET    /api/v1/ownership-transfers/{transfer}
 */
final class ApiClientAndOwnershipTransferApiTest extends TestCase
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
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $transfer = OwnershipTransfer::create([
            'art_lot_id'           => 1,
            'from_user_id'         => $from->id,
            'to_user_id'           => $to->id,
            'channel'              => 'sell_now',
            'transfer_price_cents' => 10000,
            'transferred_at'       => now(),
        ]);
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        return $transfer;
    }

    // ── API clients ───────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_api_clients(): void
    {
        $this->getJson('/api/v1/api-clients')->assertStatus(401);
    }

    public function test_buyer_cannot_list_api_clients(): void
    {
        $this->actingAs($this->buyer())
            ->getJson('/api/v1/api-clients')
            ->assertStatus(403);
    }

    public function test_admin_can_list_api_clients(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/api-clients')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_unauthenticated_cannot_create_api_client(): void
    {
        $this->postJson('/api/v1/api-clients', ['name' => 'Test'])->assertStatus(401);
    }

    public function test_buyer_cannot_create_api_client(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/api-clients', ['name' => 'Test'])
            ->assertStatus(403);
    }

    public function test_admin_can_create_api_client(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/api-clients', [
                'name'   => 'External Integration',
                'scopes' => ['artworks:read'],
            ])
            ->assertStatus(201);

        $this->assertArrayHasKey('key', $response->json());
        $this->assertSame('External Integration', $response->json('data.name'));
    }

    public function test_store_api_client_validates_required_name(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/api-clients', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_revoke_api_client(): void
    {
        [$client] = ApiClient::generate(name: 'Revokable', scopes: []);

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/api-clients/{$client->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('api_clients', ['id' => $client->id]);
    }

    // ── Ownership transfers ───────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_transfers(): void
    {
        $this->getJson('/api/v1/ownership-transfers')->assertStatus(401);
    }

    public function test_user_sees_own_transfers_only(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $buyer3 = $this->buyer();

        $this->transfer($buyer1, $buyer2);
        $this->transfer($buyer2, $buyer3); // buyer1 not involved

        $response = $this->actingAs($buyer1)
            ->getJson('/api/v1/ownership-transfers')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_admin_sees_all_transfers(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $buyer3 = $this->buyer();

        $this->transfer($buyer1, $buyer2);
        $this->transfer($buyer2, $buyer3);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/ownership-transfers')
            ->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_unauthenticated_cannot_show_transfer(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $t      = $this->transfer($buyer1, $buyer2);

        $this->getJson("/api/v1/ownership-transfers/{$t->id}")->assertStatus(401);
    }

    public function test_party_can_show_own_transfer(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $t      = $this->transfer($buyer1, $buyer2);

        $this->actingAs($buyer1)
            ->getJson("/api/v1/ownership-transfers/{$t->id}")
            ->assertOk();

        $this->actingAs($buyer2)
            ->getJson("/api/v1/ownership-transfers/{$t->id}")
            ->assertOk();
    }

    public function test_unrelated_user_cannot_show_transfer(): void
    {
        $buyer1   = $this->buyer();
        $buyer2   = $this->buyer();
        $intruder = $this->buyer();
        $t        = $this->transfer($buyer1, $buyer2);

        $this->actingAs($intruder)
            ->getJson("/api/v1/ownership-transfers/{$t->id}")
            ->assertStatus(403);
    }
}
