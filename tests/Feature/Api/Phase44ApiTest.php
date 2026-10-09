<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 44: Exhibition PATCH, Admin Book CRUD, Admin settlement-line list/disburse,
 *           HandleStripeWebhook FinalizePaidBookPurchase wiring.
 */
final class Phase44ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeGeoLocality(): int
    {
        $regionId = DB::table('geo_regions')->insertGetId([
            'name' => 'Region ' . uniqid(), 'slug' => 'region-' . uniqid(), 'code' => 'R' . rand(100, 999), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $muniId = DB::table('geo_municipalities')->insertGetId([
            'geo_region_id' => $regionId, 'name' => 'Muni ' . uniqid(), 'slug' => 'muni-' . uniqid(), 'code' => 'M' . rand(100, 999), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('geo_localities')->insertGetId([
            'geo_municipality_id' => $muniId, 'name' => 'Locality ' . uniqid(), 'slug' => 'locality-' . uniqid(), 'ekatte' => (string) rand(10000, 99999), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeVenue(): Venue
    {
        $localityId = $this->makeGeoLocality();
        return Venue::create([
            'name'           => 'Venue ' . uniqid(),
            'slug'           => 'venue-' . uniqid(),
            'geo_locality_id'=> $localityId,
            'is_active'      => true,
        ]);
    }

    private function makeExhibition(Venue $venue): object
    {
        $id = DB::table('exhibitions')->insertGetId([
            'title'     => 'Exhibition ' . uniqid(),
            'slug'      => 'exhibition-' . uniqid(),
            'venue_id'  => $venue->id,
            'starts_at' => now()->addDays(1),
            'ends_at'   => now()->addDays(10),
            'is_active' => false,
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);
        return DB::table('exhibitions')->find($id);
    }

    private function makeSettlementAndLine(): array
    {
        $settlementId = DB::table('settlements')->insertGetId([
            'stripe_payment_intent_id' => 'pi_' . uniqid(),
            'stripe_event_id'          => 'evt_' . uniqid(),
            'gross_cents'              => 10000,
            'currency'                 => 'BGN',
            'profile_key'              => 'default',
            'profile_version'          => 1,
            'artist_bps'               => 7500,
            'fund_bps'                 => 1000,
            'ops_bps'                  => 1500,
            'artist_cents'             => 7500,
            'fund_cents'               => 1000,
            'ops_cents'                => 1500,
            'status'                   => 'completed',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $lineId = DB::table('settlement_lines')->insertGetId([
            'settlement_id' => $settlementId,
            'recipient_type'=> 'artist',
            'amount_cents'  => 7500,
            'currency'      => 'BGN',
            'status'        => 'pending',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [$settlementId, $lineId];
    }

    // ── Exhibition PATCH ──────────────────────────────────────────────────────

    public function test_admin_can_update_exhibition(): void
    {
        $admin  = User::factory()->create(['role' => 'admin']);
        $venue  = $this->makeVenue();
        $exh    = $this->makeExhibition($venue);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/exhibitions/{$exh->id}", [
                'title'     => 'Updated Title',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_gallery_staff_can_update_exhibition(): void
    {
        $staff  = User::factory()->create(['role' => 'artist']);
        $venue  = $this->makeVenue();
        $exh    = $this->makeExhibition($venue);

        $gallery = Gallery::create([
            'name'    => 'Gallery ' . uniqid(),
            'slug'    => 'gallery-' . uniqid(),
            'venue_id'=> $venue->id,
            'status'  => 'active',
        ]);

        GalleryStaff::create([
            'gallery_id' => $gallery->id,
            'user_id'    => $staff->id,
            'role'       => 'curator',
            'status'     => 'active',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/v1/exhibitions/{$exh->id}", ['description' => 'Great show'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Great show');
    }

    public function test_non_staff_cannot_update_exhibition(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $venue = $this->makeVenue();
        $exh   = $this->makeExhibition($venue);

        $this->actingAs($buyer, 'sanctum')
            ->patchJson("/api/v1/exhibitions/{$exh->id}", ['title' => 'Hijack'])
            ->assertForbidden();
    }

    // ── Admin Book CRUD ───────────────────────────────────────────────────────

    public function test_admin_can_create_book(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'artist']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/books', [
                'owner_id'   => $owner->id,
                'title'      => 'Test Book',
                'price_cents'=> 1990,
                'is_free'    => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Test Book')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_admin_can_update_book(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'artist']);

        $book = Book::create([
            'owner_id'   => $owner->id,
            'title'      => 'Old Title',
            'slug'       => 'old-title-' . time(),
            'status'     => 'draft',
            'is_free'    => false,
            'currency'   => 'BGN',
            'price_cents'=> 500,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/books/{$book->id}", [
                'title'       => 'New Title',
                'is_featured' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title')
            ->assertJsonPath('data.is_featured', true);
    }

    public function test_non_admin_cannot_create_book_via_admin_endpoint(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $owner  = User::factory()->create(['role' => 'artist']);

        $this->actingAs($artist, 'sanctum')
            ->postJson('/api/v1/admin/books', [
                'owner_id' => $owner->id,
                'title'    => 'Sneaky Book',
            ])
            ->assertForbidden();
    }

    // ── Admin settlement lines ────────────────────────────────────────────────

    public function test_admin_can_list_settlement_lines(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeSettlementAndLine();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/settlement-lines')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_disburse_requires_pending_transfer_outbox(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, $lineId] = $this->makeSettlementAndLine();

        // No transfer_outbox row → 422
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/settlement-lines/{$lineId}/disburse")
            ->assertStatus(422);
    }
}
