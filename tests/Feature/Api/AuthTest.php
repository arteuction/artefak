<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_token(): void
    {
        $this->postJson('/api/v1/register', [
            'name'                  => 'Test Artist',
            'email'                 => 'artist@example.com',
            'password'              => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertCreated()
          ->assertJsonStructure(['user', 'token'])
          ->assertJsonPath('user.email', 'artist@example.com');

        $this->assertDatabaseHas('users', ['email' => 'artist@example.com']);
    }

    public function test_duplicate_email_rejected(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->postJson('/api/v1/register', [
            'name'                  => 'Dupe',
            'email'                 => 'dupe@example.com',
            'password'              => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertUnprocessable();
    }

    public function test_login_returns_token(): void
    {
        User::factory()->create([
            'email'    => 'login@example.com',
            'password' => bcrypt('mypassword'),
        ]);

        $this->postJson('/api/v1/login', [
            'email'    => 'login@example.com',
            'password' => 'mypassword',
        ])->assertOk()
          ->assertJsonStructure(['user', 'token']);
    }

    public function test_wrong_password_returns_422(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/v1/login', [
            'email'    => 'user@example.com',
            'password' => 'wrongpassword',
        ])->assertUnprocessable();
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/logout')->assertOk();
    }

    public function test_unauthenticated_me_returns_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_authenticated_me_returns_profile(): void
    {
        $user = User::factory()->create(['name' => 'Gallery User']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
             ->assertOk()
             ->assertJsonPath('name', 'Gallery User');
    }
}
