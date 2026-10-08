<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ImpactProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImpactProjectUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    private function project(): ImpactProject
    {
        static $n = 0;
        $n++;
        return ImpactProject::create([
            'title'      => "Project $n",
            'slug'       => "project-$n",
            'sdg_number' => $n % 17 + 1,
            'status'     => 'planned',
        ]);
    }

    public function test_admin_can_update_impact_project(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $project = $this->project();

        $res = $this->actingAs($admin)
            ->patchJson("/api/v1/impact-projects/{$project->id}", [
                'status' => 'active',
                'title'  => 'Updated Title',
            ]);

        $res->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('title', 'Updated Title');
    }

    public function test_admin_can_add_evidence_to_project(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $project = $this->project();

        $res = $this->actingAs($admin)
            ->postJson("/api/v1/impact-projects/{$project->id}/evidence", [
                'type'   => 'impact',
                'issuer' => 'UN Office',
                'notes'  => 'Verified by field team',
            ]);

        $res->assertCreated()
            ->assertJsonPath('type', 'impact')
            ->assertJsonPath('verification_status', 'pending');
    }

    public function test_unauthenticated_cannot_update_project(): void
    {
        $project = $this->project();

        $this->patchJson("/api/v1/impact-projects/{$project->id}", ['status' => 'active'])
            ->assertUnauthorized();
    }

    public function test_evidence_index_is_public(): void
    {
        $project = $this->project();

        $this->getJson("/api/v1/impact-projects/{$project->id}/evidence")
            ->assertOk();
    }
}
