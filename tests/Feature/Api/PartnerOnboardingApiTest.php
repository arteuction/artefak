<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for partner onboarding endpoint:
 *   POST /api/v1/partners/onboard
 */
final class PartnerOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Национален исторически музей',
            'type' => 'museum',
        ], $overrides);
    }

    public function test_unauthenticated_cannot_onboard_partner(): void
    {
        $this->postJson('/api/v1/partners/onboard', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_buyer_cannot_onboard_partner(): void
    {
        $this->actingAs($this->buyer())
            ->postJson('/api/v1/partners/onboard', $this->validPayload())
            ->assertStatus(403);
    }

    public function test_admin_can_onboard_partner(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload())
            ->assertStatus(201);

        $this->assertArrayHasKey('institution', $response->json());
        $this->assertArrayHasKey('api_client', $response->json());
        $this->assertArrayHasKey('api_key', $response->json());
    }

    public function test_onboard_response_contains_plaintext_api_key(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload())
            ->assertStatus(201);

        $key = $response->json('api_key');
        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    public function test_onboard_validates_required_name(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', ['type' => 'museum'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_onboard_validates_required_type(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', ['name' => 'Test Museum'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_onboard_validates_type_enum(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload(['type' => 'corporation']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_onboard_validates_country_code_length(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload(['country_code' => 'BGR_INVALID']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country_code']);
    }

    public function test_onboard_validates_contact_email_format(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload(['contact_email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_email']);
    }

    public function test_onboard_validates_website_url_format(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload(['website_url' => 'not-a-url']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['website_url']);
    }

    public function test_onboard_with_all_optional_fields(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/partners/onboard', $this->validPayload([
                'country_code'          => 'BG',
                'website_url'           => 'https://museum.bg',
                'contact_email'         => 'contact@museum.bg',
                'scopes'                => ['artworks:read', 'lots:read'],
                'rate_limit_per_minute' => 120,
            ]))
            ->assertStatus(201);

        $this->assertSame('BG', $response->json('institution.country_code'));
    }
}
