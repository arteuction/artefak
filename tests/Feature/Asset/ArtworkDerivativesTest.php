<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\GenerateArtworkDerivatives;
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

        // All derivative keys must be under the derivatives/ subdirectory
        foreach ($result as $name => $derivKey) {
            $this->assertStringContainsString('derivatives/', $derivKey,
                "{$name} key must be in derivatives/ directory.");
            $this->assertStringEndsWith('.webp', $derivKey,
                "{$name} must be a WebP file.");
        }

        // Derivatives should be persisted on the model
        $artwork->refresh();
        $this->assertNotNull($artwork->image_derivatives);
        $this->assertArrayHasKey('thumb', $artwork->image_derivatives);
    }

    // ── HTTP endpoint ──────────────────────────────────────────────────────────

    public function test_derivatives_endpoint_requires_auth(): void
    {
        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);

        $this->postJson("/api/v1/artworks/{$artwork->id}/images/derivatives")
            ->assertUnauthorized();
    }

    public function test_non_owner_cannot_generate_derivatives(): void
    {
        $owner = $this->artist();
        $other = $this->artist();
        $art   = $this->artworkWithConfirmedImage($owner);

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$art->id}/images/derivatives")
            ->assertForbidden();
    }

    public function test_owner_can_trigger_derivatives_generation(): void
    {
        Storage::fake('s3');

        $artist  = $this->artist();
        $artwork = $this->artworkWithConfirmedImage($artist);

        Storage::disk('s3')->put($artwork->primary_image_key, $this->validJpeg());

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->id}/images/derivatives")
            ->assertOk()
            ->assertJsonStructure(['derivatives' => ['thumb', 'medium', 'large']]);
    }
}
