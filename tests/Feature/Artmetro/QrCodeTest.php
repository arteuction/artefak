<?php

declare(strict_types=1);

namespace Tests\Feature\ArtMetro;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 96 — QR code generation endpoints.
 */
final class QrCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_artwork_qr_returns_svg_for_listed_artwork(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'    => $user->id,
            'title'      => 'QR Test',
            'slug'       => Str::uuid()->toString(),
            'status'     => 'listed',
            'medium'     => 'painting',
            'is_original' => true,
        ]);

        $response = $this->get("/api/v1/artworks/{$artwork->slug}/qr");
        $response->assertOk();
        $this->assertStringContainsString('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_artwork_qr_returns_404_for_draft(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'    => $user->id,
            'title'      => 'Draft QR',
            'slug'       => Str::uuid()->toString(),
            'status'     => 'draft',
            'medium'     => 'painting',
            'is_original' => true,
        ]);

        $this->getJson("/api/v1/artworks/{$artwork->slug}/qr")->assertNotFound();
    }
}
