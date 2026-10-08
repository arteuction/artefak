<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DisputeApiTest extends TestCase
{
    use RefreshDatabase;

    private function artLot(): ArtLot
    {
        $user = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create(['user_id' => $user->id, 'title' => 'T', 'slug' => 'd-' . uniqid(), 'status' => 'listed']);
        return ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $user->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);
    }

    public function test_user_can_open_dispute(): void
    {
        $user   = User::factory()->create(['role' => 'artist']);
        $artLot = $this->artLot();

        $res = $this->actingAs($user)
            ->postJson('/api/v1/disputes', [
                'type'        => 'condition_mismatch',
                'description' => 'The artwork arrived damaged.',
                'art_lot_id'  => $artLot->id,
            ]);

        $res->assertCreated()
            ->assertJsonPath('type', 'condition_mismatch')
            ->assertJsonPath('status', 'open');
    }

    public function test_duplicate_open_dispute_rejected(): void
    {
        $user   = User::factory()->create(['role' => 'artist']);
        $artLot = $this->artLot();

        Dispute::create([
            'type'        => 'condition_mismatch',
            'description' => 'First',
            'opened_by'   => $user->id,
            'art_lot_id'  => $artLot->id,
            'status'      => 'open',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/disputes', [
                'type'        => 'condition_mismatch',
                'description' => 'Second',
                'art_lot_id'  => $artLot->id,
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_resolve_dispute(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $opener  = User::factory()->create(['role' => 'artist']);
        $artLot  = $this->artLot();

        $dispute = Dispute::create([
            'type'        => 'other',
            'description' => 'Issue',
            'opened_by'   => $opener->id,
            'art_lot_id'  => $artLot->id,
            'status'      => 'open',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/disputes/{$dispute->id}/resolve", [
                'outcome'    => 'resolved',
                'resolution' => 'Refund issued to buyer.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');
    }

    public function test_user_can_only_see_own_disputes(): void
    {
        $userA  = User::factory()->create(['role' => 'artist']);
        $userB  = User::factory()->create(['role' => 'artist']);
        $artLot = $this->artLot();

        Dispute::create(['type' => 'other', 'description' => 'A', 'opened_by' => $userA->id, 'art_lot_id' => $artLot->id, 'status' => 'open']);
        Dispute::create(['type' => 'other', 'description' => 'B', 'opened_by' => $userB->id, 'art_lot_id' => $artLot->id, 'status' => 'open']);

        $res = $this->actingAs($userA)->getJson('/api/v1/disputes');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
    }
}
