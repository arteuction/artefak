<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\ImpactProject;
use App\Models\ImpactProjectPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImpactProjectPartnerTest extends TestCase
{
    use RefreshDatabase;

    private ImpactProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = ImpactProject::create([
            'title'                => 'Test Project',
            'slug'                 => 'test-project',
            'funding_target_cents' => 100_000,
        ]);
    }

    public function test_partner_can_be_created_for_project(): void
    {
        $partner = ImpactProjectPartner::create([
            'impact_project_id' => $this->project->id,
            'organization_name' => 'Sofia Art Foundation',
            'role'              => 'lead',
        ]);

        $this->assertSame('active', $partner->status);
        $this->assertSame('lead', $partner->role);
        $this->assertSame($this->project->id, $partner->impact_project_id);
    }

    public function test_all_valid_roles_can_be_stored(): void
    {
        foreach (ImpactProjectPartner::ROLES as $index => $role) {
            ImpactProjectPartner::create([
                'impact_project_id' => $this->project->id,
                'organization_name' => "Org {$index}",
                'role'              => $role,
            ]);
        }

        $this->assertSame(count(ImpactProjectPartner::ROLES), $this->project->partners()->count());
    }

    public function test_active_partners_excludes_ended(): void
    {
        ImpactProjectPartner::create([
            'impact_project_id' => $this->project->id,
            'organization_name' => 'Active Org',
            'role'              => 'co_funder',
            'status'            => 'active',
        ]);

        ImpactProjectPartner::create([
            'impact_project_id' => $this->project->id,
            'organization_name' => 'Ended Org',
            'role'              => 'beneficiary',
            'status'            => 'ended',
        ]);

        $this->assertSame(2, $this->project->partners()->count());
        $this->assertSame(1, $this->project->activePartners()->count());
        $this->assertSame('Active Org', $this->project->activePartners()->first()->organization_name);
    }

    public function test_partner_belongs_to_project(): void
    {
        $partner = ImpactProjectPartner::create([
            'impact_project_id' => $this->project->id,
            'organization_name' => 'Child org',
            'role'              => 'implementation',
        ]);

        $this->assertTrue($partner->project->is($this->project));
    }

    public function test_is_active_helper(): void
    {
        $partner = ImpactProjectPartner::create([
            'impact_project_id' => $this->project->id,
            'organization_name' => 'Org',
            'role'              => 'other',
        ]);

        $this->assertTrue($partner->isActive());

        $partner->update(['status' => 'ended']);
        $partner->refresh();
        $this->assertFalse($partner->isActive());
    }

    public function test_organization_can_appear_in_multiple_projects(): void
    {
        $project2 = ImpactProject::create([
            'title'                => 'Second Project',
            'slug'                 => 'second-project',
            'funding_target_cents' => 50_000,
        ]);

        foreach ([$this->project->id, $project2->id] as $projectId) {
            ImpactProjectPartner::create([
                'impact_project_id' => $projectId,
                'organization_name' => 'United Nations',
                'role'              => 'co_funder',
            ]);
        }

        $count = ImpactProjectPartner::where('organization_name', 'United Nations')->count();
        $this->assertSame(2, $count);
    }

    public function test_optional_fields_can_be_null(): void
    {
        $partner = ImpactProjectPartner::create([
            'impact_project_id' => $this->project->id,
            'organization_name' => 'Minimal Org',
            'role'              => 'lead',
        ]);

        $this->assertNull($partner->organization_type);
        $this->assertNull($partner->url);
        $this->assertNull($partner->started_on);
        $this->assertNull($partner->ended_on);
    }
}
