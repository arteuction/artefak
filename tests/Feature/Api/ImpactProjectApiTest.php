<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ImpactProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImpactProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_projects(): void
    {
        ImpactProject::create([
            'title'                => 'Clean Water',
            'slug'                 => 'clean-water',
            'sdg_number'           => 6,
            'status'               => 'active',
            'funding_target_cents' => 1000000,
        ]);

        $this->getJson('/api/v1/impact-projects')
             ->assertOk()
             ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_project(): void
    {
        $project = ImpactProject::create([
            'title'      => 'Education',
            'slug'       => 'education-' . uniqid(),
            'sdg_number' => 4,
            'status'     => 'planned',
        ]);

        $this->getJson("/api/v1/impact-projects/{$project->id}")
             ->assertOk()
             ->assertJsonPath('id', $project->id);
    }

    public function test_stats_returns_aggregate(): void
    {
        ImpactProject::create([
            'title'                => 'Forest',
            'slug'                 => 'forest-' . uniqid(),
            'sdg_number'           => 15,
            'status'               => 'active',
            'funding_target_cents' => 500000,
            'funding_actual_cents' => 250000,
        ]);

        $response = $this->getJson('/api/v1/impact-projects/stats')
             ->assertOk();

        $response->assertJsonStructure([
            'total_projects',
            'active_projects',
            'total_funded_cents',
            'total_target_cents',
            'total_donors',
            'verified_evidence',
            'total_beneficiaries',
        ]);

        $this->assertSame(1, $response->json('total_projects'));
        $this->assertSame(1, $response->json('active_projects'));
        $this->assertSame(250000, $response->json('total_funded_cents'));
    }

    public function test_stats_is_public(): void
    {
        // No auth required
        $this->getJson('/api/v1/impact-projects/stats')->assertOk();
    }
}
