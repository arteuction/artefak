<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\GenerateArtworkDerivatives;
use App\Jobs\GenerateArtworkDerivativesJob;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 85 — Artwork image derivatives pipeline tests.
 */
final class ArtworkDerivativesTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function validJpeg(): string
    {
        $img = imagecreatetruecolor(10, 10);
        imagecolorallocate($img, 255, 128, 0);
        ob_start();
        imagejpeg($img);
        imagedestroy($img);
        return ob_get_clean();
    }

    private function artworkWithConfirmedImage(User $owner): Artwork
    {
        $artwork = Artwork::create([
            'user_id'              => $owner->id,
            'title'                => 'Derivative Test',
            'slug'                 => Str::uuid()->toString(),
            'status'               => 'draft',
            'is_original'          => true,
            'primary_image_key'    => "artworks/{$owner->id}/images/test-uuid.jpg",
            'primary_image_status' => 'confirmed',
        ]);
        return $artwork;
    }

    // ── Domain action ─────────────────────────────────────────────────────────

    public function test_generate_derivatives_throws_when_no_confirmed_image(): void
    {
        $artist  = $this->artist();
        $artwork = Artwork::create([
            'user_id'              => $artist->id,
            'title'                => 'No Image',
            'slug'                 => Str::uuid()->toString(),
            'status'               => 'draft',
            'is_original'          => true,
            'primary_image_key'    => null,
            'primary_image_status' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/confirmed/i');

        (new GenerateArtworkDerivatives())->execute($artwork);
    }

    public function test_generate_derivatives_throws_when_image_pending(): void
    {
        $artist  = $this->artist();
        $artwork = Artwork::create([
            'user_id'              => $artist->id,
            'title'                => 'Pending Image',
            'slug'                 => Str::uuid()->toString(),
            'status'               => 'draft',
            'is_original'          => true,
            'primary_image_key'    => 'artworks/1/images/test.jpg',
            'primary_image_status' => 'pending',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new GenerateArtworkDerivatives())->execute($artwork);
    }

    public function test_generate_derivatives_produces_three_sizes_and_persists(): void
    {
        Storage::fake('s3');

        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);
        $key     = $artwork->primary_image_key;

        Storage::disk('s3')->put($key, $this->validJpeg());

        $result = (new GenerateArtworkDerivatives())->execute($artwork);

        $this->assertArrayHasKey('thumb', $result);
        $this->assertArrayHasKey('medium', $result);
        $this->assertArrayHasKey('large', $result);

        // Keys must be versioned: derivatives/{sha8}/{size}.webp
        $expectedVersion = substr(hash('sha256', $key), 0, 8);
        foreach ($result as $name => $derivKey) {
            $this->assertStringContainsString("derivatives/{$expectedVersion}/", $derivKey,
                "{$name} key must contain the versioned derivatives path.");
            $this->assertStringEndsWith('.webp', $derivKey,
                "{$name} must be a WebP file.");
        }

        // Derivatives should be persisted on the model
        $artwork->refresh();
        $this->assertNotNull($artwork->image_derivatives);
        $this->assertArrayHasKey('thumb', $artwork->image_derivatives);
    }

    public function test_stale_job_is_discarded_when_source_key_changed(): void
    {
        Storage::fake('s3');

        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);
        $oldKey  = $artwork->primary_image_key;

        // Job was dispatched for the old key
        $job = new GenerateArtworkDerivativesJob($artwork->id, $oldKey);

        // Artwork's source image is replaced before the job runs
        $newKey = "artworks/{$artist->id}/images/new-uuid.jpg";
        $artwork->update(['primary_image_key' => $newKey]);

        // Job should exit without generating anything
        Storage::disk('s3')->put($newKey, $this->validJpeg());
        $job->handle();

        $artwork->refresh();
        $this->assertNull($artwork->image_derivatives,
            'Stale job must not overwrite derivative pointers set by a newer source.');
    }

    public function test_job_generates_derivatives_when_source_key_matches(): void
    {
        Storage::fake('s3');

        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);

        Storage::disk('s3')->put($artwork->primary_image_key, $this->validJpeg());

        $job = new GenerateArtworkDerivativesJob($artwork->id, $artwork->primary_image_key);
        $job->handle();

        $artwork->refresh();
        $this->assertNotNull($artwork->image_derivatives);
        $this->assertArrayHasKey('thumb', $artwork->image_derivatives);
    }

    // ── HTTP endpoint ──────────────────────────────────────────────────────────

    public function test_derivatives_endpoint_requires_auth(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);

        $this->postJson("/api/v1/artworks/{$artwork->slug}/images/derivatives")
            ->assertUnauthorized();
    }

    public function test_non_owner_cannot_generate_derivatives(): void
    {
        $owner = $this->artist();
        $other = $this->artist();
        $art   = $this->artworkWithConfirmedImage($owner);

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$art->slug}/images/derivatives")
            ->assertForbidden();
    }

    public function test_owner_can_trigger_derivatives_generation(): void
    {
        Storage::fake('s3');

        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);

        Storage::disk('s3')->put($artwork->primary_image_key, $this->validJpeg());

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/images/derivatives")
            ->assertAccepted()
            ->assertJson(['status' => 'processing']);
    }
}
