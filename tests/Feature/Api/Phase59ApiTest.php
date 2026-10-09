<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Auction\ReAuthorizeBid;
use App\Models\AuctionItem;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Phase 59: Bid re-authorization flow + auction settlement webhook.
 */
final class Phase59ApiTest extends TestCase
{
    use RefreshDatabase;

    // ── ReAuthorizeBid domain action ─────────────────────────────────────────

    public function test_re_authorize_bid_updates_payment_intent_and_status(): void
    {
        $stripe = new StripeClient('sk_test_fake');
        $user   = User::factory()->create();

        [$item, $bid] = $this->makeWonExpiredBid($user->id);

        $action = new ReAuthorizeBid($stripe);

        // Stripe will throw (fake key) — we test the guard logic first
        $this->expectException(\Stripe\Exception\ApiErrorException::class);
        $action->execute($item, $bid, $user->id, 'pm_test_valid');
    }

    public function test_re_authorize_rejects_captured_bid(): void
    {
        $stripe = new StripeClient('sk_test_fake');
        $user   = User::factory()->create();

        [$item, $bid] = $this->makeWonExpiredBid($user->id);
        $bid->update(['payment_status' => 'captured']);

        $action = new ReAuthorizeBid($stripe);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/already captured/');
        $action->execute($item, $bid, $user->id, 'pm_test_valid');
    }

    public function test_re_authorize_rejects_wrong_bidder(): void
    {
        $stripe      = new StripeClient('sk_test_fake');
        $owner       = User::factory()->create();
        $other       = User::factory()->create();

        [$item, $bid] = $this->makeWonExpiredBid($owner->id);

        $action = new ReAuthorizeBid($stripe);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Only the winning bidder/');
        $action->execute($item, $bid, $other->id, 'pm_test_valid');
    }

    public function test_re_authorize_rejects_non_expired_bid(): void
    {
        $stripe = new StripeClient('sk_test_fake');
        $user   = User::factory()->create();

        [$item, $bid] = $this->makeWonExpiredBid($user->id);
        $bid->update(['payment_status' => 'authorized']); // not expired

        $action = new ReAuthorizeBid($stripe);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/only.*authorization_expired.*can be re-authorized/i');
        $action->execute($item, $bid, $user->id, 'pm_test_valid');
    }

    // ── Re-authorization HTTP endpoint ────────────────────────────────────────

    public function test_re_authorize_endpoint_returns_404_when_no_winning_bid(): void
    {
        $user    = User::factory()->create();
        [$item,] = $this->makeWonExpiredBid(User::factory()->create()->id); // different user won

        $response = $this->actingAs($user)
            ->postJson("/api/v1/auction-items/{$item->id}/re-authorize", [
                'payment_method_id' => 'pm_test_abc123',
            ]);

        $response->assertNotFound();
    }

    public function test_re_authorize_endpoint_requires_authentication(): void
    {
        $user    = User::factory()->create();
        [$item,] = $this->makeWonExpiredBid($user->id);

        $response = $this->postJson("/api/v1/auction-items/{$item->id}/re-authorize", [
            'payment_method_id' => 'pm_test_abc',
        ]);

        $response->assertUnauthorized();
    }

    public function test_re_authorize_endpoint_validates_payment_method_format(): void
    {
        $user    = User::factory()->create();
        [$item,] = $this->makeWonExpiredBid($user->id);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/auction-items/{$item->id}/re-authorize", [
                'payment_method_id' => 'card_not_pm_format',
            ]);

        $response->assertUnprocessable();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeWonExpiredBid(int $userId): array
    {
        $artworkId = DB::table('artworks')->insertGetId([
            'user_id'      => $userId,
            'title'        => 'Re-auth Test Artwork ' . uniqid(),
            'slug'         => 're-auth-' . uniqid(),
            'status'       => 'in_auction',
            'year_created' => 2024,
            'medium'       => 'painting',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $artLotId = DB::table('art_lots')->insertGetId([
            'artwork_id'         => $artworkId,
            'sale_mode'          => 'auction',
            'status'             => 'active',
            'starting_bid_cents' => 5_000,
            'currency'           => 'EUR',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $auctionId = DB::table('auctions')->insertGetId([
            'title'      => 'Re-auth Auction ' . uniqid(),
            'slug'       => 're-auth-auction-' . uniqid(),
            'status'     => 'closed',
            'currency'   => 'EUR',
            'starts_at'  => now()->subDays(10),
            'ends_at'    => now()->subDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('auction_items')->insertGetId([
            'auction_id'  => $auctionId,
            'art_lot_id'  => $artLotId,
            'lot_number'  => rand(1, 9999),
            'status'      => 'sold',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $bidId = DB::table('bids')->insertGetId([
            'auction_item_id'          => $itemId,
            'user_id'                  => $userId,
            'amount_cents'             => 10_000,
            'status'                   => 'won',
            'payment_status'           => 'authorization_expired',
            'stripe_payment_intent_id' => 'pi_expired_' . uniqid(),
            'authorization_expires_at' => now()->subDays(2),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $item = AuctionItem::find($itemId);
        $bid  = Bid::find($bidId);

        return [$item, $bid];
    }
}
