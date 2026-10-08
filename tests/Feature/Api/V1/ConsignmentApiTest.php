<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConsignmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User    $owner;
    private User    $other;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = User::factory()->create(['role' => 'artist']);
        $this->other   = User::factory()->create(['role' => 'artist']);
        $this->artwork = Artwork::create([
            'user_id' => $this->owner->id,
            'title'   => 'Consign Art',
            'slug'    => 'consign-art-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/consignments')->assertUnauthorized();
    }

    public function test_index_returns_own_consignments(): void
    {
        // owner's consignment
        Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        // another user's consignment — should not appear
        $otherArtwork = Artwork::create([
            'user_id' => $this->other->id,
            'title'   => 'Other Art',
            'slug'    => 'other-art-' . uniqid(),
            'status'  => 'listed',
        ]);
        Consignment::create([
            'artwork_id'     => $otherArtwork->id,
            'owner_id'       => $this->other->id,
            'consignor_id'   => $this->other->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/consignments')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }

    public function test_show_returns_403_for_unrelated_user(): void
    {
        $consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($this->other)
            ->getJson("/api/v1/consignments/{$consignment->id}")
            ->assertForbidden();
    }

    public function test_store_creates_consignment(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/consignments', [
                'artwork_id'     => $this->artwork->id,
                'consignor_id'   => $this->owner->id,
                'commission_bps' => 1000,
            ])
            ->assertCreated()
            ->assertJsonFragment(['status' => 'draft', 'commission_bps' => 1000]);
    }

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/v1/consignments', [])->assertUnauthorized();
    }

    public function test_activate_consignment(): void
    {
        $consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/consignments/{$consignment->id}/activate")
            ->assertOk()
            ->assertJsonFragment(['status' => 'active']);
    }

    public function test_activate_returns_403_for_unrelated_user(): void
    {
        $consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->owner->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->actingAs($this->other)
            ->postJson("/api/v1/consignments/{$consignment->id}/activate")
            ->assertForbidden();
    }
}
