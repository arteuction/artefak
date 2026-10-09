<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 93 — Laravel Scout catalogue search.
 *
 * Uses the 'collection' Scout driver (no external service required).
 * Verifies that the search endpoint returns correct results and that
 * the Artwork model's shouldBeSearchable() gate works correctly.
 */
final class ArtworkSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_endpoint_returns_paginated_response(): void
    {
        $this->getJson('/api/v1/search/artworks')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    public function test_search_returns_only_listed_artworks(): void
    {
        $user = User::factory()->create();
        Artwork::create([
            'user_id' => $user->id, 'title' => 'Listed Work',
            'slug' => Str::uuid(), 'status' => 'listed', 'is_original' => true,
        ]);
        Artwork::create([
            'user_id' => $user->id, 'title' => 'Draft Hidden',
            'slug' => Str::uuid(), 'status' => 'draft', 'is_original' => true,
        ]);

        $res = $this->getJson('/api/v1/search/artworks')->assertOk();
        $titles = collect($res->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('Listed Work'));
        $this->assertFalse($titles->contains('Draft Hidden'));
    }

    public function test_search_with_q_filters_by_title(): void
    {
        $user = User::factory()->create();
        Artwork::create([
            'user_id' => $user->id, 'title' => 'Moonlit Sonata',
            'slug' => Str::uuid(), 'status' => 'listed', 'is_original' => true,
        ]);
        Artwork::create([
            'user_id' => $user->id, 'title' => 'Summer Fields',
            'slug' => Str::uuid(), 'status' => 'listed', 'is_original' => true,
        ]);

        $res = $this->getJson('/api/v1/search/artworks?q=Moonlit')->assertOk();
        $titles = collect($res->json('data'))->pluck('title');

        $this->assertTrue($titles->contains('Moonlit Sonata'));
        $this->assertFalse($titles->contains('Summer Fields'));
    }

    public function test_artwork_should_be_searchable_only_when_listed(): void
    {
        $user = User::factory()->create();
        $draft  = Artwork::create(['user_id' => $user->id, 'title' => 'D', 'slug' => Str::uuid(), 'status' => 'draft',  'is_original' => true]);
        $listed = Artwork::create(['user_id' => $user->id, 'title' => 'L', 'slug' => Str::uuid(), 'status' => 'listed', 'is_original' => true]);

        $this->assertFalse($draft->shouldBeSearchable());
        $this->assertTrue($listed->shouldBeSearchable());
    }

    public function test_artwork_to_searchable_array_contains_required_keys(): void
    {
        $user    = User::factory()->create();
        $artwork = Artwork::create([
            'user_id' => $user->id, 'title' => 'Test', 'slug' => Str::uuid(),
            'status' => 'listed', 'is_original' => true,
        ]);

        $array = $artwork->toSearchableArray();

        foreach (['id', 'title', 'status'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }
}
