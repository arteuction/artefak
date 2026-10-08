<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DomainEventApiTest extends TestCase
{
    use RefreshDatabase;

    private User    $admin;
    private User    $regularUser;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin       = User::factory()->create(['role' => 'admin']);
        $this->regularUser = User::factory()->create(['role' => 'buyer']);
        $this->artwork     = Artwork::create([
            'user_id' => $this->admin->id,
            'title'   => 'Outbox Art',
            'slug'    => 'outbox-art-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/domain-events')->assertUnauthorized();
    }

    public function test_index_requires_admin_role(): void
    {
        $this->actingAs($this->regularUser)
            ->getJson('/api/v1/domain-events')
            ->assertForbidden();
    }

    public function test_admin_can_list_domain_events(): void
    {
        $lot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->admin->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($lot, 'art_lot.test_api', ['x' => 1]);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/domain-events')
            ->assertOk()
            ->assertJsonFragment(['event_type' => 'art_lot.test_api']);
    }

    public function test_admin_can_filter_by_status(): void
    {
        $lot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->admin->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($lot, 'art_lot.event_a', []);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/domain-events?status=pending')
            ->assertOk()
            ->assertJsonFragment(['event_type' => 'art_lot.event_a']);
    }

    public function test_admin_can_filter_by_event_type(): void
    {
        $lot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->admin->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);

        (new AppendDomainEvent())->execute($lot, 'art_lot.specific_type', []);
        (new AppendDomainEvent())->execute($lot, 'art_lot.other_type', []);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/domain-events?event_type=art_lot.specific_type')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
    }
}
