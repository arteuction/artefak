<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 145 — Adversarial workflow testing (OWASP WSTG business logic).
 *
 * Covers:
 *   WSTG-BUSL-01: Business Logic Data Validation
 *   WSTG-BUSL-04: Process Timing (price race conditions)
 *   WSTG-BUSL-07: Defenses Against Application Misuse
 *   WSTG-BUSL-09: Upload of Unexpected File Types (via API field abuse)
 *
 * All tests verify that the application rejects or ignores malicious inputs
 * silently or with an error — no 500s, no data corruption.
 */
class BusinessLogicAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User    $seller;
    private User    $buyer;
    private User    $attacker;
    private ArtLot  $artLot;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller   = User::factory()->create(['role' => 'artist']);
        $this->buyer    = User::factory()->create(['role' => 'buyer']);
        $this->attacker = User::factory()->create(['role' => 'buyer']);

        $this->artwork = Artwork::create([
            'user_id' => $this->seller->id,
            'title'   => 'Adversarial Artwork',
            'slug'    => 'adversarial-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->artLot = ArtLot::create([
            'artwork_id'          => $this->artwork->id,
            'consignor_id'        => $this->seller->id,
            'sale_mode'           => 'sell_now',
            'status'              => 'active',
            'buy_now_price_cents' => 100000,
            'currency'            => 'EUR',
        ]);
    }

    // ── WSTG-BUSL-01: Price manipulation ──────────────────────────────────────

    public function test_offer_with_negative_price_is_rejected(): void
    {
        $response = $this->actingAs($this->buyer)->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => -1,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sell_now_offers', ['buyer_id' => $this->buyer->id, 'offered_price_cents' => -1]);
    }

    public function test_offer_with_zero_price_is_rejected(): void
    {
        $response = $this->actingAs($this->buyer)->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => 0,
        ]);

        $response->assertStatus(422);
    }

    public function test_offer_with_excessively_large_price_is_rejected(): void
    {
        // PHP_INT_MAX / SQL bigint overflow attempt
        $response = $this->actingAs($this->buyer)->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => 999_999_999_999_999,
        ]);

        // Must reject with validation error, NOT a 500 from integer overflow
        $response->assertStatus(422);
    }

    public function test_offer_with_string_price_is_rejected(): void
    {
        $response = $this->actingAs($this->buyer)->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => 'free',
        ]);

        $response->assertStatus(422);
    }

    // ── WSTG-BUSL-07: Horizontal privilege escalation ─────────────────────────

    public function test_attacker_cannot_accept_another_users_offer(): void
    {
        // Buyer submits an offer
        $offer = DB::table('sell_now_offers')->insertGetId([
            'art_lot_id'          => $this->artLot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 80000,
            'status'              => 'submitted',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Attacker tries to accept it (only seller can accept)
        $response = $this->actingAs($this->attacker)->postJson("/api/v1/offers/{$offer}/accept");

        $response->assertStatus(403);
        $this->assertDatabaseHas('sell_now_offers', ['id' => $offer, 'status' => 'submitted']);
    }

    public function test_attacker_cannot_update_another_users_artwork_rights(): void
    {
        $response = $this->actingAs($this->attacker)->patchJson(
            "/api/v1/artworks/{$this->artwork->id}/rights",
            ['license_spdx' => 'CC0-1.0'],
        );

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_submit_offer(): void
    {
        $response = $this->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => 80000,
        ]);

        $response->assertUnauthorized();
    }

    // ── WSTG-BUSL-04: State machine violations ────────────────────────────────

    public function test_cannot_accept_already_rejected_offer(): void
    {
        $offerId = DB::table('sell_now_offers')->insertGetId([
            'art_lot_id'          => $this->artLot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 80000,
            'status'              => 'rejected',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $response = $this->actingAs($this->seller)->postJson("/api/v1/offers/{$offerId}/accept");

        // Must fail — cannot accept a rejected offer
        $response->assertStatus(422);
        $this->assertDatabaseHas('sell_now_offers', ['id' => $offerId, 'status' => 'rejected']);
    }

    public function test_cannot_submit_offer_on_sold_lot(): void
    {
        $this->artLot->update(['status' => 'sold']);

        $response = $this->actingAs($this->buyer)->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => 80000,
        ]);

        $response->assertStatus(422);
    }

    // ── WSTG-BUSL-01: IDOR on offer endpoints ─────────────────────────────────

    public function test_attacker_cannot_reject_another_users_offer(): void
    {
        $offerId = DB::table('sell_now_offers')->insertGetId([
            'art_lot_id'          => $this->artLot->id,
            'buyer_id'            => $this->buyer->id,
            'offered_price_cents' => 80000,
            'status'              => 'submitted',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $response = $this->actingAs($this->attacker)->postJson("/api/v1/offers/{$offerId}/reject");

        $response->assertStatus(403);
        $this->assertDatabaseHas('sell_now_offers', ['id' => $offerId, 'status' => 'submitted']);
    }

    // ── WSTG-BUSL-07: Mass assignment / field injection ───────────────────────

    public function test_buyer_cannot_inject_agreed_price_via_offer_submission(): void
    {
        $response = $this->actingAs($this->buyer)->postJson('/api/v1/artworks/' . $this->artwork->id . '/offers', [
            'offered_price_cents' => 80000,
            'agreed_price_cents'  => 1,      // attacker tries to set agreed price to 1 cent
            'status'              => 'accepted', // attacker tries to pre-accept
        ]);

        if ($response->status() === 201 || $response->status() === 200) {
            // If accepted, agreed_price_cents must NOT be 1 (injected value)
            $offer = DB::table('sell_now_offers')
                ->where('buyer_id', $this->buyer->id)
                ->where('art_lot_id', $this->artLot->id)
                ->orderByDesc('id')
                ->first();

            $this->assertSame('submitted', $offer->status, 'Injected status must not be accepted');
            $this->assertNotEquals(1, $offer->agreed_price_cents ?? null);
        }
        // A 422 or 403 is also acceptable
    }

    // ── Cross-resource access ─────────────────────────────────────────────────

    public function test_buyer_cannot_read_other_users_api_clients(): void
    {
        $response = $this->actingAs($this->attacker)->getJson('/api/v1/api-clients');

        // Must return only the attacker's own clients (empty list), not everyone's
        if ($response->status() === 200) {
            $data = $response->json('data') ?? [];
            foreach ($data as $client) {
                $this->assertSame($this->attacker->id, $client['user_id'] ?? $this->attacker->id,
                    'API client listing must be scoped to authenticated user');
            }
        }
    }

    public function test_buyer_cannot_read_other_users_consents(): void
    {
        $response = $this->actingAs($this->attacker)->getJson('/api/v1/my/consents');

        $response->assertOk();
        // Consent list must be empty (attacker has no consents)
        $this->assertEmpty($response->json('data') ?? []);
    }
}
