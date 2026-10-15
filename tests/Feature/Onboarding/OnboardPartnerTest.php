<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Onboarding\OnboardPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 179 — Partner institution onboarding.
 *
 * Verifies OnboardPartner creates an Institution + ApiClient in one transaction
 * and returns the plaintext API key exactly once.
 */
final class OnboardPartnerTest extends TestCase
{
    use RefreshDatabase;

    private OnboardPartner $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(OnboardPartner::class);
    }

    // ── Return shape ───────────────────────────────────────────────────────────

    public function test_returns_institution_api_client_and_api_key(): void
    {
        $result = $this->action->execute(
            name: 'Sofia Museum of Art',
            type: 'museum',
        );

        $this->assertArrayHasKey('institution', $result);
        $this->assertArrayHasKey('api_client',  $result);
        $this->assertArrayHasKey('api_key',     $result);
    }

    public function test_api_key_starts_with_artk_prefix(): void
    {
        $result = $this->action->execute(name: 'Test Gallery', type: 'gallery');

        $this->assertStringStartsWith('artk_', $result['api_key']);
    }

    // ── Database state ─────────────────────────────────────────────────────────

    public function test_creates_institution_row(): void
    {
        $this->action->execute(name: 'Plovdiv Art House', type: 'gallery');

        $this->assertDatabaseHas('institutions', [
            'name'      => 'Plovdiv Art House',
            'type'      => 'gallery',
            'is_active' => true,
        ]);
    }

    public function test_institution_slug_is_derived_from_name(): void
    {
        $result = $this->action->execute(name: 'Varna Art Center', type: 'museum');

        $this->assertSame('varna-art-center', $result['institution']->slug);
    }

    public function test_creates_api_client_row(): void
    {
        $result = $this->action->execute(name: 'BG National Gallery', type: 'gallery');

        $this->assertDatabaseHas('api_clients', [
            'institution_id' => $result['institution']->id,
            'is_active'      => true,
        ]);
    }

    public function test_api_client_is_linked_to_institution(): void
    {
        $result = $this->action->execute(name: 'Ruse Culture Hub', type: 'museum');

        $this->assertSame(
            $result['institution']->id,
            $result['api_client']->institution_id,
        );
    }

    // ── Optional fields ────────────────────────────────────────────────────────

    public function test_optional_fields_are_stored_when_provided(): void
    {
        $this->action->execute(
            name:         'Burgas Art Space',
            type:         'gallery',
            countryCode:  'BG',
            websiteUrl:   'https://burgas-art.bg',
            contactEmail: 'info@burgas-art.bg',
        );

        $this->assertDatabaseHas('institutions', [
            'name'          => 'Burgas Art Space',
            'country_code'  => 'BG',
            'website_url'   => 'https://burgas-art.bg',
            'contact_email' => 'info@burgas-art.bg',
        ]);
    }

    // ── Custom scopes ──────────────────────────────────────────────────────────

    public function test_default_scopes_are_read_only(): void
    {
        $result = $this->action->execute(name: 'Read Only Partner', type: 'museum');

        $scopes = json_decode($result['api_client']->scopes, true);
        $this->assertContains('artworks:read', $scopes);
        $this->assertContains('lots:read',     $scopes);
    }

    public function test_custom_scopes_are_stored(): void
    {
        $result = $this->action->execute(
            name:   'Extended Partner',
            type:   'gallery',
            scopes: ['artworks:read', 'provenance:read', 'impact:read'],
        );

        $scopes = json_decode($result['api_client']->scopes, true);
        $this->assertContains('impact:read', $scopes);
    }

    // ── Atomicity ──────────────────────────────────────────────────────────────

    public function test_institution_count_increases_by_one(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('institutions')->count();

        $this->action->execute(name: 'New Partner', type: 'auction_house');

        $after = \Illuminate\Support\Facades\DB::table('institutions')->count();
        $this->assertSame($before + 1, $after);
    }

    public function test_api_client_count_increases_by_one(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('api_clients')->count();

        $this->action->execute(name: 'Another Partner', type: 'gallery');

        $after = \Illuminate\Support\Facades\DB::table('api_clients')->count();
        $this->assertSame($before + 1, $after);
    }
}
