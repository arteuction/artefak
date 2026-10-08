<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\ActivateConsignment;
use App\Domain\Asset\CreateConsignment;
use App\Models\Artwork;
use App\Models\Consignment;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ConsignmentTest extends TestCase
{
    use RefreshDatabase;

    private User    $owner;
    private User    $consignor;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner     = User::factory()->create(['role' => 'artist']);
        $this->consignor = User::factory()->create(['role' => 'artist']);
        $this->artwork   = Artwork::create([
            'user_id' => $this->owner->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_create_consignment_with_same_owner_and_consignor(): void
    {
        $action = new CreateConsignment();

        $consignment = $action->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->owner,
            commissionBps: 1500,
        );

        $this->assertInstanceOf(Consignment::class, $consignment);
        $this->assertSame('draft', $consignment->status);
        $this->assertSame(1500, $consignment->commission_bps);
        $this->assertTrue($consignment->ownerIsConsignor());
        $this->assertSame(0.15, $consignment->commissionRate());
    }

    public function test_create_consignment_with_different_owner_and_consignor(): void
    {
        $action = new CreateConsignment();

        $consignment = $action->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->consignor,
            commissionBps: 2000,
        );

        $this->assertSame($this->owner->id, $consignment->owner_id);
        $this->assertSame($this->consignor->id, $consignment->consignor_id);
        $this->assertFalse($consignment->ownerIsConsignor());
    }

    public function test_create_consignment_with_gallery(): void
    {
        $gallery = Gallery::create(['name' => 'Test Gallery', 'slug' => 'test-gallery-' . uniqid(), 'status' => 'active']);

        $action = new CreateConsignment();

        $consignment = $action->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->consignor,
            gallery: $gallery,
        );

        $this->assertSame($gallery->id, $consignment->gallery_id);
        $this->assertInstanceOf(Gallery::class, $consignment->gallery);
    }

    public function test_create_consignment_with_date_range(): void
    {
        $starts = now()->addDay();
        $ends   = now()->addMonths(6);

        $action = new CreateConsignment();

        $consignment = $action->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->consignor,
            startsAt: $starts,
            endsAt: $ends,
        );

        $this->assertNotNull($consignment->starts_at);
        $this->assertNotNull($consignment->ends_at);
    }

    public function test_create_consignment_with_zero_commission(): void
    {
        $action = new CreateConsignment();

        $consignment = $action->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->consignor,
            commissionBps: 0,
        );

        $this->assertSame(0, $consignment->commission_bps);
        $this->assertSame(0.0, $consignment->commissionRate());
    }

    public function test_create_consignment_rejects_commission_over_10000(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commission_bps must be 0–10000');

        (new CreateConsignment())->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->consignor,
            commissionBps: 10001,
        );
    }

    public function test_create_consignment_rejects_ends_before_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ends_at must be after starts_at');

        (new CreateConsignment())->execute(
            artwork: $this->artwork,
            owner: $this->owner,
            consignor: $this->consignor,
            startsAt: now()->addDays(5),
            endsAt: now()->addDays(1),
        );
    }

    public function test_activate_consignment_advances_to_active(): void
    {
        $consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->consignor->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $activated = (new ActivateConsignment())->execute($consignment);

        $this->assertSame('active', $activated->status);
        $this->assertTrue($activated->isActive());
        $this->assertNotNull($activated->starts_at);
    }

    public function test_activate_consignment_preserves_existing_starts_at(): void
    {
        $starts = now()->subDay();

        $consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->consignor->id,
            'commission_bps' => 0,
            'status'         => 'draft',
            'starts_at'      => $starts,
        ]);

        $activated = (new ActivateConsignment())->execute($consignment);

        $this->assertTrue($activated->starts_at->isSameMinute($starts));
    }

    public function test_activate_already_active_consignment_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $consignment = Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->consignor->id,
            'commission_bps' => 0,
            'status'         => 'active',
        ]);

        (new ActivateConsignment())->execute($consignment);
    }

    public function test_artwork_has_consignments_relation(): void
    {
        Consignment::create([
            'artwork_id'     => $this->artwork->id,
            'owner_id'       => $this->owner->id,
            'consignor_id'   => $this->consignor->id,
            'commission_bps' => 0,
            'status'         => 'draft',
        ]);

        $this->assertCount(1, $this->artwork->consignments);
    }
}
