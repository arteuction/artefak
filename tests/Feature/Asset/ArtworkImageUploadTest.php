<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\ConfirmArtworkImageUpload;
use App\Domain\Asset\RequestArtworkImageUpload;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 80 — Artwork image upload pipeline.
 *
 * The presigned S3 flow:
 *   1. POST /api/v1/artworks/{artwork}/images/presign  → returns upload_url + key
 *   2. Client PUTs the file directly to S3
 *   3. POST /api/v1/artworks/{artwork}/images/confirm  → verifies object exists, marks 'confirmed'
 *
 * Tests use Storage::fake('s3') — no real AWS credentials needed.
 * For the presign endpoint, temporaryUploadUrl() is not available on the fake disk,
 * so we test via the domain action directly (skipping the URL generation) and
 * test the HTTP endpoint with a mocked disk via config.
 */
final class ArtworkImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeArtist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function makeArtwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'    => $artist->id,
            'title'      => 'Test Artwork',
            'slug'       => 'test-artwork-' . uniqid(),
            'status'     => 'draft',
            'is_original' => true,
        ]);
    }

    // ── Domain: RequestArtworkImageUpload ────────────────────────────────────

    public function test_request_stores_pending_key_on_artwork(): void
    {
        Storage::fake('s3');

        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist);

        // Fake the disk so temporaryUploadUrl returns something
        Storage::shouldReceive('disk')->with('s3')->andReturnSelf();
        Storage::shouldReceive('temporaryUploadUrl')->andReturn('https://s3.example.com/presigned');

        $result = (new RequestArtworkImageUpload())->execute($artwork, 'jpg');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('expires_in_seconds', $result);
        $this->assertArrayHasKey('max_bytes', $result);
        $this->assertStringContainsString("artworks/{$artwork->id}/images/", $result['key']);
        $this->assertStringEndsWith('.jpg', $result['key']);

        $artwork->refresh();
        $this->assertSame($result['key'], $artwork->primary_image_key);
        $this->assertSame('pending', $artwork->primary_image_status);
    }

    public function test_request_rejects_unsupported_extension(): void
    {
        $artwork = $this->makeArtwork($this->makeArtist());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported image extension/');
        (new RequestArtworkImageUpload())->execute($artwork, 'gif');
    }

    // ── Domain: ConfirmArtworkImageUpload ────────────────────────────────────

    public function test_confirm_transitions_status_to_confirmed(): void
    {
        Storage::fake('s3');

        $artwork = $this->makeArtwork($this->makeArtist());
        $key     = "artworks/{$artwork->id}/images/test.jpg";

        $artwork->update([
            'primary_image_key'    => $key,
            'primary_image_status' => 'pending',
        ]);

        // Put a fake file so exists() returns true
        Storage::disk('s3')->put($key, 'fake-image-data');

        $updated = (new ConfirmArtworkImageUpload())->execute($artwork);

        $this->assertSame('confirmed', $updated->primary_image_status);
        $this->assertSame($key, $updated->primary_image_key);
    }

    public function test_confirm_fails_when_file_not_in_storage(): void
    {
        Storage::fake('s3');

        $artwork = $this->makeArtwork($this->makeArtist());
        $artwork->update([
            'primary_image_key'    => 'artworks/999/images/missing.jpg',
            'primary_image_status' => 'pending',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Image not found in storage/');
        (new ConfirmArtworkImageUpload())->execute($artwork);
    }

    public function test_confirm_fails_when_no_pending_upload(): void
    {
        $artwork = $this->makeArtwork($this->makeArtist());
        // No pending upload — primary_image_status is null

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no pending image upload/');
        (new ConfirmArtworkImageUpload())->execute($artwork);
    }

    // ── HTTP: confirm endpoint ───────────────────────────────────────────────

    public function test_confirm_endpoint_requires_authentication(): void
    {
        $artwork = $this->makeArtwork($this->makeArtist());

        $this->postJson("/api/v1/artworks/{$artwork->slug}/images/confirm")
            ->assertUnauthorized();
    }

    public function test_confirm_endpoint_returns_403_for_non_owner(): void
    {
        Storage::fake('s3');
        $artist  = $this->makeArtist();
        $other   = $this->makeArtist();
        $artwork = $this->makeArtwork($artist);

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$artwork->slug}/images/confirm")
            ->assertForbidden();
    }

    public function test_confirm_endpoint_confirms_uploaded_image(): void
    {
        Storage::fake('s3');

        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist);
        $key     = "artworks/{$artwork->id}/images/test.jpg";

        $artwork->update([
            'primary_image_key'    => $key,
            'primary_image_status' => 'pending',
        ]);
        Storage::disk('s3')->put($key, 'fake-image-bytes');

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/images/confirm")
            ->assertOk()
            ->assertJsonPath('primary_image_status', 'confirmed')
            ->assertJsonPath('primary_image_key', $key);
    }

    public function test_confirm_endpoint_returns_422_when_file_missing_in_s3(): void
    {
        Storage::fake('s3');

        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist);

        $artwork->update([
            'primary_image_key'    => 'artworks/123/images/ghost.jpg',
            'primary_image_status' => 'pending',
        ]);
        // File never uploaded to fake S3

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/images/confirm")
            ->assertUnprocessable();
    }

    // ── Security: owner-only access ──────────────────────────────────────────

    public function test_presign_endpoint_requires_authentication(): void
    {
        $artwork = $this->makeArtwork($this->makeArtist());

        $this->postJson("/api/v1/artworks/{$artwork->slug}/images/presign")
            ->assertUnauthorized();
    }

    public function test_presign_endpoint_returns_403_for_non_owner(): void
    {
        $artist  = $this->makeArtist();
        $other   = $this->makeArtist();
        $artwork = $this->makeArtwork($artist);

        $this->actingAs($other)
            ->postJson("/api/v1/artworks/{$artwork->slug}/images/presign")
            ->assertForbidden();
    }

    // ── Allowed extension validation ─────────────────────────────────────────

    public function test_presign_endpoint_rejects_invalid_extension(): void
    {
        $artist  = $this->makeArtist();
        $artwork = $this->makeArtwork($artist);

        $this->actingAs($artist)
            ->postJson("/api/v1/artworks/{$artwork->slug}/images/presign", ['extension' => 'gif'])
            ->assertUnprocessable();
    }
}
