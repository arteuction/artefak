<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Events\AuctionItemStatusChanged;
use App\Events\BidOutbid;
use App\Events\GalleryOperationalAlert;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Phase 50: WebSocket channel authorization and broadcast event definitions.
 *
 * Tests cover:
 *  - Channel authorization callbacks (invoked directly — no Pusher SDK needed)
 *  - Event broadcastOn() and broadcastAs() contract
 */
final class Phase50ApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Invoke the channel authorization callback directly.
     * Returns the callback's result (true/false/array) or throws if no channel registered.
     */
    private function authChannel(User $user, string $channelPattern, ...$params): mixed
    {
        Auth::setUser($user);

        // Resolve the registered channel callback from Laravel's broadcaster
        $manager     = app(\Illuminate\Broadcasting\BroadcastManager::class);
        $broadcaster = $manager->connection();

        // Channel callbacks are stored on the broadcaster; retrieve via reflection
        $channels = (function () { return $this->channels; })->call($broadcaster);

        if (! isset($channels[$channelPattern])) {
            throw new \RuntimeException("No channel registered for pattern: {$channelPattern}");
        }

        return ($channels[$channelPattern])($user, ...$params);
    }

    // ── auction-item.{itemId} — any authenticated user ────────────────────────

    public function test_authenticated_user_can_auth_auction_item_channel(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $this->assertTrue($this->authChannel($user, 'auction-item.{itemId}', 99));
    }

    // ── bidder.{userId} — self-only ───────────────────────────────────────────

    public function test_user_can_auth_their_own_bidder_channel(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $this->assertTrue($this->authChannel($user, 'bidder.{userId}', $user->id));
    }

    public function test_user_cannot_auth_another_users_bidder_channel(): void
    {
        $user  = User::factory()->create(['role' => 'buyer']);
        $other = User::factory()->create(['role' => 'buyer']);
        $this->assertFalse($this->authChannel($user, 'bidder.{userId}', $other->id));
    }

    // ── gallery.{galleryId} — staff or admin ─────────────────────────────────

    public function test_gallery_staff_can_auth_their_gallery_channel(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $gallery = Gallery::create([
            'name'          => 'Gallery A',
            'slug'          => 'gallery-a-' . uniqid(),
            'owner_user_id' => $user->id,
            'status'        => 'active',
        ]);
        GalleryStaff::create([
            'gallery_id' => $gallery->id,
            'user_id'    => $user->id,
            'role'       => 'manager',
            'status'     => 'active',
        ]);

        $this->assertTrue($this->authChannel($user, 'gallery.{galleryId}', $gallery->id));
    }

    public function test_non_staff_cannot_auth_gallery_channel(): void
    {
        $owner    = User::factory()->create(['role' => 'artist']);
        $intruder = User::factory()->create(['role' => 'buyer']);
        $gallery  = Gallery::create([
            'name'          => 'Gallery B',
            'slug'          => 'gallery-b-' . uniqid(),
            'owner_user_id' => $owner->id,
            'status'        => 'active',
        ]);

        $this->assertFalse($this->authChannel($intruder, 'gallery.{galleryId}', $gallery->id));
    }

    public function test_admin_can_auth_gallery_channel_without_being_staff(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $owner   = User::factory()->create(['role' => 'artist']);
        $gallery = Gallery::create([
            'name'          => 'Gallery C',
            'slug'          => 'gallery-c-' . uniqid(),
            'owner_user_id' => $owner->id,
            'status'        => 'active',
        ]);

        $this->assertTrue($this->authChannel($admin, 'gallery.{galleryId}', $gallery->id));
    }

    public function test_inactive_staff_cannot_auth_gallery_channel(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $gallery = Gallery::create([
            'name'          => 'Gallery D',
            'slug'          => 'gallery-d-' . uniqid(),
            'owner_user_id' => $user->id,
            'status'        => 'active',
        ]);
        GalleryStaff::create([
            'gallery_id' => $gallery->id,
            'user_id'    => $user->id,
            'role'       => 'assistant',
            'status'     => 'revoked',   // not active
        ]);

        $this->assertFalse($this->authChannel($user, 'gallery.{galleryId}', $gallery->id));
    }

    // ── admin — admin/operator only ───────────────────────────────────────────

    public function test_admin_can_auth_admin_channel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($this->authChannel($admin, 'admin'));
    }

    public function test_buyer_cannot_auth_admin_channel(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $this->assertFalse($this->authChannel($buyer, 'admin'));
    }

    // ── Event definitions ─────────────────────────────────────────────────────

    public function test_bid_outbid_event_broadcasts_on_private_bidder_channel(): void
    {
        $event = new BidOutbid(
            userId: 42,
            auctionItemId: 7,
            auctionId: 3,
            previousBidCents: 10000,
            newLeadingBidCents: 12000,
            currency: 'BGN',
        );

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertStringContainsString('bidder.42', $channels[0]->name);
        $this->assertSame('bid.outbid', $event->broadcastAs());
    }

    public function test_auction_item_status_changed_broadcasts_on_public_auction_channel(): void
    {
        $event = new AuctionItemStatusChanged(
            auctionItemId: 5,
            auctionId: 2,
            newStatus: 'sold',
            hammerPriceCents: 15000,
            currency: 'BGN',
        );

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertStringContainsString('auction.2', $channels[0]->name);
        $this->assertSame('auction-item.status-changed', $event->broadcastAs());
    }

    public function test_gallery_operational_alert_broadcasts_on_private_gallery_channel(): void
    {
        $event = new GalleryOperationalAlert(
            galleryId: 9,
            type: 'consignment_submitted',
            message: 'New consignment awaiting review.',
        );

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertStringContainsString('gallery.9', $channels[0]->name);
        $this->assertSame('gallery.alert', $event->broadcastAs());
    }
}
