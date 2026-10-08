<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WatchlistApiTest extends TestCase
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
            'title'   => 'Watchable',
            'slug'    => 'watchable-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_watch_artwork(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/watchlist', [
            'subject_type' => 'artwork',
            'subject_id'   => $this->artwork->id,
        ])->assertCreated();
    }

    public function test_watch_is_idempotent_via_api(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/watchlist', ['subject_type' => 'artwork', 'subject_id' => $this->artwork->id])
             ->assertCreated();
        $this->postJson('/api/v1/watchlist', ['subject_type' => 'artwork', 'subject_id' => $this->artwork->id])
             ->assertCreated();

        $this->getJson('/api/v1/watchlist')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_returns_own_watchlist(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/watchlist', ['subject_type' => 'artwork', 'subject_id' => $this->artwork->id]);

        $this->getJson('/api/v1/watchlist')
             ->assertOk()
             ->assertJsonCount(1, 'data');
    }

    public function test_unwatch_via_delete(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/watchlist', ['subject_type' => 'artwork', 'subject_id' => $this->artwork->id]);

        $this->deleteJson("/api/v1/watchlist/artwork/{$this->artwork->id}")
             ->assertNoContent();

        $this->getJson('/api/v1/watchlist')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_subject_type_returns_404(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/watchlist', [
            'subject_type' => 'unknown_type',
            'subject_id'   => 1,
        ])->assertUnprocessable();
    }

    public function test_unauthenticated_cannot_watch(): void
    {
        $this->postJson('/api/v1/watchlist', ['subject_type' => 'artwork', 'subject_id' => $this->artwork->id])
             ->assertUnauthorized();
    }
}
