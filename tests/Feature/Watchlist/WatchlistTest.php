<?php

declare(strict_types=1);

namespace Tests\Feature\Watchlist;

use App\Domain\Watchlist\Unwatch;
use App\Domain\Watchlist\Watch;
use App\Models\Artwork;
use App\Models\ArtLot;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    private User    $user;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user    = User::factory()->create();
        $this->artwork = Artwork::create([
            'user_id' => $this->user->id,
            'title'   => 'Watch Me',
            'slug'    => 'watch-me-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_watch_creates_watchlist_row(): void
    {
        (new Watch())->execute($this->user, $this->artwork);

        $this->assertDatabaseHas('watchlist', [
            'user_id'      => $this->user->id,
            'subject_type' => Artwork::class,
            'subject_id'   => $this->artwork->id,
        ]);
    }

    public function test_watch_is_idempotent(): void
    {
        (new Watch())->execute($this->user, $this->artwork);
        (new Watch())->execute($this->user, $this->artwork);

        $this->assertSame(1, WatchlistItem::where([
            'user_id'      => $this->user->id,
            'subject_type' => Artwork::class,
            'subject_id'   => $this->artwork->id,
        ])->count());
    }

    public function test_unwatch_removes_row(): void
    {
        (new Watch())->execute($this->user, $this->artwork);
        (new Unwatch())->execute($this->user, $this->artwork);

        $this->assertDatabaseMissing('watchlist', [
            'user_id'      => $this->user->id,
            'subject_type' => Artwork::class,
            'subject_id'   => $this->artwork->id,
        ]);
    }

    public function test_unwatch_is_idempotent(): void
    {
        (new Unwatch())->execute($this->user, $this->artwork);

        $this->assertDatabaseMissing('watchlist', [
            'user_id'  => $this->user->id,
            'subject_id' => $this->artwork->id,
        ]);
    }

    public function test_different_users_can_watch_same_artwork(): void
    {
        $other = User::factory()->create();

        (new Watch())->execute($this->user, $this->artwork);
        (new Watch())->execute($other, $this->artwork);

        $this->assertSame(2, WatchlistItem::where([
            'subject_type' => Artwork::class,
            'subject_id'   => $this->artwork->id,
        ])->count());
    }

    public function test_watchlist_item_has_morphable_subject(): void
    {
        $item = (new Watch())->execute($this->user, $this->artwork);

        $this->assertInstanceOf(Artwork::class, $item->subject);
        $this->assertSame($this->artwork->id, $item->subject->id);
    }
}
