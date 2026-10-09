<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\CloseArtLot;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 78 — CloseArtLot domain action: lot lifecycle + artwork status sync.
 *
 * Invariant:
 *   When the last active lot for an artwork closes as 'sold', artwork.status → 'sold'.
 *   When the last active lot closes as 'unsold', artwork.status → 'listed'.
 *   When other active lots remain, artwork.status is not changed.
 */
final class CloseArtLotTest extends TestCase
{
    use RefreshDatabase;

    private CloseArtLot $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CloseArtLot();
    }

    private function makeArtist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function makeArtwork(User $artist, string $status = 'listed'): Artwork
    {
        return Artwork::create([
            'user_id'    => $artist->id,
            'title'      => 'Test Work',
            'slug'       => 'test-work-' . uniqid(),
            'status'     => $status,
            'is_original' => true,
        ]);
    }

    private function makeLot(Artwork $artwork, string $status = 'active'): ArtLot
    {
        return ArtLot::create([
            'artwork_id' => $artwork->id,
            'status'     => $status,
            'currency'   => 'EUR',
        ]);
    }

    // ── lot status transitions ─────────────────────────────────────────────────

    public function test_active_lot_closes_as_sold(): void
    {
        $lot = $this->makeLot($this->makeArtwork($this->makeArtist()));

        $result = $this->action->execute($lot, CloseArtLot::STATUS_SOLD, soldPriceCents: 100_00);

        $this->assertSame('sold', $result->status);
        $this->assertNotNull($result->closed_at);
    }

    public function test_active_lot_closes_as_unsold(): void
    {
        $lot = $this->makeLot($this->makeArtwork($this->makeArtist()));

        $result = $this->action->execute($lot, CloseArtLot::STATUS_UNSOLD);

        $this->assertSame('unsold', $result->status);
    }

    public function test_closing_already_sold_lot_is_idempotent(): void
    {
        $lot = $this->makeLot($this->makeArtwork($this->makeArtist()), 'sold');

        $result = $this->action->execute($lot, CloseArtLot::STATUS_SOLD);

        $this->assertSame('sold', $result->status);
    }

    public function test_closing_from_invalid_status_throws(): void
    {
        $lot = $this->makeLot($this->makeArtwork($this->makeArtist()), 'draft');

        $this->expectException(\DomainException::class);
        $this->action->execute($lot, CloseArtLot::STATUS_SOLD);
    }

    // ── artwork status sync ───────────────────────────────────────────────────

    public function test_artwork_transitions_to_sold_when_last_lot_closes_sold(): void
    {
        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist, 'listed');
        $lot     = $this->makeLot($artwork);

        $this->action->execute($lot, CloseArtLot::STATUS_SOLD, soldPriceCents: 50_000);

        $artwork->refresh();
        $this->assertSame('sold', $artwork->status);
    }

    public function test_artwork_transitions_to_listed_when_last_lot_closes_unsold(): void
    {
        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist, 'in_auction');
        $lot     = $this->makeLot($artwork, 'scheduled');

        $this->action->execute($lot, CloseArtLot::STATUS_UNSOLD);

        $artwork->refresh();
        $this->assertSame('listed', $artwork->status);
    }

    public function test_artwork_status_unchanged_when_other_active_lots_remain(): void
    {
        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist, 'in_auction');

        $lot1 = $this->makeLot($artwork, 'active');
        $this->makeLot($artwork, 'active'); // second active lot

        $this->action->execute($lot1, CloseArtLot::STATUS_SOLD, soldPriceCents: 50_000);

        $artwork->refresh();
        // Second lot is still active — artwork should NOT transition to sold
        $this->assertSame('in_auction', $artwork->status);
    }

    public function test_artwork_status_unchanged_when_scheduled_lot_remains(): void
    {
        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist, 'in_auction');

        $lot1 = $this->makeLot($artwork, 'catalogued');
        $this->makeLot($artwork, 'scheduled'); // still pending in an upcoming auction

        $this->action->execute($lot1, CloseArtLot::STATUS_UNSOLD);

        $artwork->refresh();
        $this->assertSame('in_auction', $artwork->status);
    }
}
