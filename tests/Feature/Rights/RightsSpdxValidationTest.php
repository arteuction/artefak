<?php

declare(strict_types=1);

namespace Tests\Feature\Rights;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 143 — SPDX identifier validation.
 *
 * Canonical SPDX identifiers use hyphens, not spaces:
 *   CORRECT:   CC-BY-NC-4.0
 *   INCORRECT: CC BY-NC 4.0
 *
 * Ref: https://spdx.org/licenses/
 */
class RightsSpdxValidationTest extends TestCase
{
    use RefreshDatabase;

    private User    $artist;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist = User::factory()->create(['role' => 'artist']);

        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'SPDX Test Artwork',
            'slug'    => 'spdx-test-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    // ── Valid identifiers ─────────────────────────────────────────────────────

    #[DataProvider('validSpdxProvider')]
    public function test_valid_spdx_identifier_is_accepted(string $spdx): void
    {
        $response = $this->actingAs($this->artist)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['license_spdx' => $spdx],
        );

        $response->assertOk();
        $this->assertSame($spdx, $response->json('license_spdx'));
    }

    public static function validSpdxProvider(): array
    {
        return [
            'CC-BY-4.0'            => ['CC-BY-4.0'],
            'CC-BY-NC-4.0'         => ['CC-BY-NC-4.0'],
            'CC-BY-SA-4.0'         => ['CC-BY-SA-4.0'],
            'CC0-1.0'              => ['CC0-1.0'],
            'MIT'                  => ['MIT'],
            'Apache-2.0'           => ['Apache-2.0'],
            'GPL-3.0-only'         => ['GPL-3.0-only'],
            'GPL-3.0-or-later'     => ['GPL-3.0-or-later'],
            'LicenseRef-custom'    => ['LicenseRef-custom'],
            'GPL-2.0-only WITH Classpath-exception-2.0' => ['GPL-2.0-only WITH Classpath-exception-2.0'],
        ];
    }

    // ── Invalid identifiers (space-separated, colloquial forms) ───────────────

    #[DataProvider('invalidSpdxProvider')]
    public function test_invalid_spdx_identifier_is_rejected(string $spdx): void
    {
        $response = $this->actingAs($this->artist)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['license_spdx' => $spdx],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['license_spdx']);
    }

    public static function invalidSpdxProvider(): array
    {
        return [
            'space-separated CC BY-NC 4.0' => ['CC BY-NC 4.0'],
            'space-separated CC BY 4.0'    => ['CC BY 4.0'],
            'space-separated CC BY SA 4.0' => ['CC BY SA 4.0'],
            'bare "Creative Commons"'       => ['Creative Commons Attribution 4.0'],
            // Note: leading/trailing spaces are stripped by TrimStrings middleware
            // before validation, so those cases are intentionally excluded here.
        ];
    }

    // ── Null clears the field ─────────────────────────────────────────────────

    public function test_null_license_spdx_is_accepted(): void
    {
        $this->artwork->update(['license_spdx' => 'CC-BY-4.0']);

        $response = $this->actingAs($this->artist)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['license_spdx' => null],
        );

        $response->assertOk();
        $this->assertNull($response->json('license_spdx'));
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_non_owner_cannot_update_rights(): void
    {
        $other = User::factory()->create(['role' => 'buyer']);

        $response = $this->actingAs($other)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['license_spdx' => 'MIT'],
        );

        $response->assertForbidden();
    }

    public function test_admin_can_update_rights(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['license_spdx' => 'MIT'],
        );

        $response->assertOk();
        $this->assertSame('MIT', $response->json('license_spdx'));
    }

    // ── Resale royalty bps ────────────────────────────────────────────────────

    public function test_resale_royalty_bps_max_5000(): void
    {
        $response = $this->actingAs($this->artist)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['resale_royalty_bps' => 5001],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['resale_royalty_bps']);
    }

    public function test_resale_royalty_bps_at_boundary_5000_is_accepted(): void
    {
        $response = $this->actingAs($this->artist)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['resale_royalty_bps' => 5000],
        );

        $response->assertOk();
        $this->assertSame(5000, $response->json('resale_royalty_bps'));
    }

    public function test_resale_royalty_bps_negative_is_rejected(): void
    {
        $response = $this->actingAs($this->artist)->patchJson(
            "/api/v1/artworks/{$this->artwork->slug}/rights",
            ['resale_royalty_bps' => -1],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['resale_royalty_bps']);
    }
}
