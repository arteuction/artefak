<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GalleryProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_profile_returns_enriched_data(): void
    {
        $gallery = Gallery::create(['name' => 'Test Gallery', 'slug' => 'test-gallery-' . uniqid(), 'status' => 'active']);
        $user    = User::factory()->create(['role' => 'artist']);

        GalleryStaff::create([
            'gallery_id' => $gallery->id,
            'user_id'    => $user->id,
            'role'       => 'curator',
            'status'     => 'active',
        ]);

        $res = $this->getJson("/api/v1/galleries/{$gallery->id}/profile");

        $res->assertOk()
            ->assertJsonStructure([
                'gallery',
                'active_lots',
                'exhibitions',
                'staff',
            ]);

        $this->assertCount(1, $res->json('staff'));
        $this->assertEquals('curator', $res->json('staff.0.role'));
    }

    public function test_gallery_profile_active_lots_empty_when_none(): void
    {
        $gallery = Gallery::create(['name' => 'Empty Gallery', 'slug' => 'empty-gallery-' . uniqid(), 'status' => 'active']);

        $res = $this->getJson("/api/v1/galleries/{$gallery->id}/profile");

        $res->assertOk()
            ->assertJsonPath('active_lots', []);
    }
}
