<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ArtworkRevisionApiTest extends TestCase
{
    use RefreshDatabase;

    private User    $artist;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist  = User::factory()->create(['role' => 'artist']);
        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Original Title',
            'slug'    => 'original-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_artist_can_create_revision(): void
    {
        Sanctum::actingAs($this->artist);

        $this->postJson("/api/v1/artworks/{$this->artwork->slug}/revisions", [
            'title'  => 'Corrected Title',
            'reason' => 'correction',
        ])->assertCreated()
          ->assertJsonPath('title', 'Corrected Title')
          ->assertJsonPath('reason', 'correction')
          ->assertJsonPath('version', 1);
    }

    public function test_second_revision_increments_version(): void
    {
        Sanctum::actingAs($this->artist);

        $this->postJson("/api/v1/artworks/{$this->artwork->slug}/revisions", [
            'title' => 'Rev 1', 'reason' => 'initial',
        ])->assertCreated()->assertJsonPath('version', 1);

        $this->postJson("/api/v1/artworks/{$this->artwork->slug}/revisions", [
            'title' => 'Rev 2', 'reason' => 'correction',
        ])->assertCreated()->assertJsonPath('version', 2);
    }

    public function test_non_owner_cannot_create_revision(): void
    {
        $other = User::factory()->create(['role' => 'artist']);
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/artworks/{$this->artwork->slug}/revisions", [
            'title' => 'Hijack', 'reason' => 'correction',
        ])->assertForbidden();
    }

    public function test_invalid_reason_rejected(): void
    {
        Sanctum::actingAs($this->artist);

        $this->postJson("/api/v1/artworks/{$this->artwork->slug}/revisions", [
            'title'  => 'Bad',
            'reason' => 'made_up_reason',
        ])->assertUnprocessable();
    }
}
