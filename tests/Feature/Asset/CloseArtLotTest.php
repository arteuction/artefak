<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\CloseArtLot;
use App\Domain\SellNow\PurchaseAtFixedPrice;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CloseArtLotTest extends TestCase
{
    use RefreshDatabase;

    private User    $artist;
    private User    $buyer;
    private Artwork $artwork;
    private ArtLot  $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist  = User::factory()->create(['role' => 'artist']);
        $this->buyer   = User::factory()->create(['role' => 'artist']);
        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Sellable',
            'slug'    => 'sellable-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->lot = ArtLot::create([
            'artwork_id'          => $this->artwork->id,
            'consignor_id'        => $this->artist->id,
            'sale_mode'           => 'sell_now',
            'status'              => 'active',
            'buy_now_price_cents' => 100000,
            'currency'            => 'EUR',
            'split_profile_key'   => 'social_pilot_45_45_10',
        ]);
    }

    // ── CloseArtLot ─────────────────────────────────────────────────

    public function test_close_lot_as_sold(): void
    {
        $closed = (new CloseArtLot())->execute(
            artLot:         $this->lot,
            outcome:        CloseArtLot::STATUS_SOLD,
            soldPriceCents: 100000,
            buyerId:        $this->buyer->id,
        );

        $this->assertSame('sold', $closed->status);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_close_lot_as_unsold(): void
    {
        $closed = (new CloseArtLot())->execute($this->lot, CloseArtLot::STATUS_UNSOLD);

        $this->assertSame('unsold', $closed->status);
    }

    public function test_close_emits_domain_event(): void
    {
        (new CloseArtLot())->execute($this->lot, CloseArtLot::STATUS_SOLD, soldPriceCents: 100000);

        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'art_lot.sold',
        ]);
    }

    public function test_close_already_closed_is_noop(): void
    {
        $this->lot->update(['status' => 'sold', 'closed_at' => now()]);

        $result = (new CloseArtLot())->execute($this->lot->fresh(), CloseArtLot::STATUS_SOLD);

        $this->assertSame('sold', $result->status);
    }

    public function test_cannot_close_draft_lot(): void
    {
        $this->lot->update(['status' => 'draft']);

        $this->expectException(\DomainException::class);

        (new CloseArtLot())->execute($this->lot->fresh(), CloseArtLot::STATUS_SOLD);
    }

    // ── PurchaseAtFixedPrice — lot closing ────────────────────────────

    public function test_purchase_closes_lot(): void
    {
        (new PurchaseAtFixedPrice())->execute($this->lot, $this->buyer);

        $this->assertSame('sold', $this->lot->fresh()->status);
    }

    public function test_purchase_expired_buy_now_throws(): void
    {
        $this->lot->update([
            'sale_mode'          => 'hybrid',
            'buy_now_expires_at' => now()->subMinute(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expired/');

        (new PurchaseAtFixedPrice())->execute($this->lot->fresh(), $this->buyer);
    }

    public function test_purchase_valid_buy_now_expiry_succeeds(): void
    {
        $this->lot->update([
            'sale_mode'          => 'hybrid',
            'buy_now_expires_at' => now()->addHour(),
        ]);

        $offer = (new PurchaseAtFixedPrice())->execute($this->lot->fresh(), $this->buyer);

        $this->assertSame('accepted', $offer->status);
    }

    public function test_purchase_lot_already_sold_throws(): void
    {
        (new PurchaseAtFixedPrice())->execute($this->lot, $this->buyer);

        $this->expectException(\InvalidArgumentException::class);

        (new PurchaseAtFixedPrice())->execute($this->lot->fresh(), $this->buyer);
    }
}
