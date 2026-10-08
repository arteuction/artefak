<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\ApproveConsignment;
use App\Domain\Asset\CreateLotFromConsignment;
use App\Domain\Asset\RequestConsignmentChanges;
use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConsignmentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User        $owner;
    private User        $curator;
    private User        $outsider;
    private Gallery     $gallery;
    private Artwork     $artwork;
    private Consignment $consignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = User::factory()->create(['role' => 'artist']);
        $this->curator  = User::factory()->create(['role' => 'artist']);
        $this->outsider = User::factory()->create(['role' => 'artist']);
        $this->gallery  = Gallery::create(['name' => 'Test Gallery', 'slug' => 'test-gallery-' . uniqid(), 'user_id' => $this->curator->id]);

        GalleryStaff::create([
            'gallery_id'  => $this->gallery->id,
            'user_id'     => $this->curator->id,
            'role'        => 'curator',
            'status'      => 'active',
            'invited_at'  => now(),
            'accepted_at' => now(),
            'invited_by'  => $this->curator->id,
        ]);

        $this->artwork = Artwork::create([
            'user_id' => $this->owner->id,
            'title'   => 'Consigned Work',
            'slug'    => 'consigned-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->owner->id,
            'gallery_id'     => $this->gallery->id,
            'commission_bps' => 1500,
            'status'         => 'draft',
        ]);
    }

    // ── ApproveConsignment ───────────────────────────────────────────

    public function test_curator_can_approve_draft_consignment(): void
    {
        $approved = (new ApproveConsignment())->execute($this->consignment, $this->curator);

        $this->assertSame('active', $approved->status);
        $this->assertNotNull($approved->starts_at);
    }

    public function test_approve_sets_starts_at_if_null(): void
    {
        $this->assertNull($this->consignment->starts_at);

        $approved = (new ApproveConsignment())->execute($this->consignment, $this->curator);

        $this->assertNotNull($approved->starts_at);
    }

    public function test_approve_emits_domain_event(): void
    {
        (new ApproveConsignment())->execute($this->consignment, $this->curator);

        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'Consignment',
            'aggregate_id'   => $this->consignment->id,
            'event_type'     => 'consignment.approved',
        ]);
    }

    public function test_approve_non_draft_throws(): void
    {
        $this->consignment->update(['status' => 'active']);

        $this->expectException(\DomainException::class);

        (new ApproveConsignment())->execute($this->consignment->fresh(), $this->curator);
    }

    public function test_outsider_cannot_approve(): void
    {
        $this->expectException(\DomainException::class);

        (new ApproveConsignment())->execute($this->consignment, $this->outsider);
    }

    // ── RequestConsignmentChanges ────────────────────────────────────

    public function test_can_request_changes_on_draft(): void
    {
        (new RequestConsignmentChanges())->execute($this->consignment, $this->curator, 'Missing provenance docs');

        $this->assertDatabaseHas('domain_events', [
            'aggregate_type' => 'Consignment',
            'event_type'     => 'consignment.changes_requested',
        ]);
    }

    public function test_request_changes_on_active_throws(): void
    {
        $this->consignment->update(['status' => 'active']);

        $this->expectException(\DomainException::class);

        (new RequestConsignmentChanges())->execute($this->consignment->fresh(), $this->curator, 'Late request');
    }

    // ── CreateLotFromConsignment ─────────────────────────────────────

    public function test_create_lot_from_active_consignment(): void
    {
        $this->consignment->update(['status' => 'active']);
        $consignment = $this->consignment->fresh();

        $lot = (new CreateLotFromConsignment())->execute($consignment, $this->curator);

        $this->assertSame($consignment->artwork_id, $lot->artwork_id);
        $this->assertSame($consignment->gallery_id, $lot->gallery_id);
        $this->assertSame($consignment->id, $lot->consignment_id);
        $this->assertSame($consignment->consignor_id, $lot->consignor_id);
        $this->assertSame('auction', $lot->sale_mode);
        $this->assertSame('active', $lot->status);
    }

    public function test_create_lot_inherits_split_profile(): void
    {
        $this->consignment->update(['status' => 'active']);

        $lot = (new CreateLotFromConsignment())->execute($this->consignment->fresh(), $this->curator, splitProfileKey: 'auction_60_30_10');

        $this->assertSame('auction_60_30_10', $lot->split_profile_key);
    }

    public function test_create_lot_hybrid_requires_buy_now_price(): void
    {
        $this->consignment->update(['status' => 'active']);

        $this->expectException(\InvalidArgumentException::class);

        (new CreateLotFromConsignment())->execute($this->consignment->fresh(), $this->curator, saleMode: 'hybrid');
    }

    public function test_create_hybrid_lot_with_buy_now_price(): void
    {
        $this->consignment->update(['status' => 'active']);

        $lot = (new CreateLotFromConsignment())->execute(
            $this->consignment->fresh(),
            $this->curator,
            saleMode: 'hybrid',
            buyNowPriceCents: 500000,
        );

        $this->assertSame('hybrid', $lot->sale_mode);
        $this->assertSame(500000, $lot->buy_now_price_cents);
    }

    public function test_create_lot_from_draft_consignment_throws(): void
    {
        $this->expectException(\DomainException::class);

        (new CreateLotFromConsignment())->execute($this->consignment, $this->curator);
    }

    public function test_create_lot_emits_domain_event(): void
    {
        $this->consignment->update(['status' => 'active']);

        $lot = (new CreateLotFromConsignment())->execute($this->consignment->fresh(), $this->curator);

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'art_lot.created_from_consignment',
        ]);
    }
}
