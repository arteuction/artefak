<?php

declare(strict_types=1);

namespace Tests\Feature\Impact;

use App\Domain\Impact\AttachEvidence;
use App\Models\Artwork;
use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\Evidence;
use App\Models\ImpactProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 13-G — ImpactProject + Evidence Registry tests.
 */
final class ImpactProjectEvidenceTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // ImpactProject
    // -----------------------------------------------------------------------

    public function test_impact_project_creates_with_defaults(): void
    {
        $project = ImpactProject::create([
            'title' => 'Clean Water Initiative',
            'slug'  => 'clean-water',
        ]);

        $this->assertSame('planned', $project->status);
        $this->assertSame(0, $project->funding_actual_cents);
        $this->assertSame('EUR', $project->currency);
    }

    public function test_funding_progress_bps_calculated_correctly(): void
    {
        $project = ImpactProject::create([
            'title'                => 'Art Education',
            'slug'                 => 'art-education',
            'funding_target_cents' => 100000,
            'funding_actual_cents' => 45000,
        ]);

        $this->assertSame(4500, $project->fundingProgressBps());
    }

    public function test_fully_funded_returns_true_when_actual_meets_target(): void
    {
        $project = ImpactProject::create([
            'title'                => 'Funded',
            'slug'                 => 'funded',
            'funding_target_cents' => 50000,
            'funding_actual_cents' => 50000,
        ]);

        $this->assertTrue($project->isFullyFunded());
    }

    public function test_fully_funded_caps_progress_at_10000_bps(): void
    {
        $project = ImpactProject::create([
            'title'                => 'Overfunded',
            'slug'                 => 'overfunded',
            'funding_target_cents' => 10000,
            'funding_actual_cents' => 20000, // 200% of target
        ]);

        $this->assertSame(10000, $project->fundingProgressBps());
        $this->assertTrue($project->isFullyFunded());
    }

    public function test_funding_progress_zero_when_no_target(): void
    {
        $project = ImpactProject::create([
            'title' => 'No Target',
            'slug'  => 'no-target',
        ]);

        $this->assertSame(0, $project->fundingProgressBps());
        $this->assertFalse($project->isFullyFunded());
    }

    public function test_partners_stored_as_json_array(): void
    {
        $partners = [
            ['name' => 'UNICEF', 'role' => 'partner'],
            ['name' => 'UNESCO', 'role' => 'co-funder', 'url' => 'https://example.com'],
        ];

        $project = ImpactProject::create([
            'title'    => 'Partnership',
            'slug'     => 'partnership',
            'partners' => $partners,
        ]);

        $project->refresh();
        $this->assertCount(2, $project->partners);
        $this->assertSame('UNICEF', $project->partners[0]['name']);
    }

    // -----------------------------------------------------------------------
    // Evidence — AttachEvidence action
    // -----------------------------------------------------------------------

    public function test_attach_evidence_to_artwork(): void
    {
        $artwork = $this->makeArtwork();

        $evidence = (new AttachEvidence())->execute($artwork, 'authenticity', [
            'issuer'    => 'National Art Gallery',
            'issued_at' => '2026-01-15',
            'subtype'   => 'certificate_of_authenticity',
        ]);

        $this->assertSame('authenticity', $evidence->type);
        $this->assertSame('pending', $evidence->verification_status);
        $this->assertSame(Artwork::class, $evidence->subject_type);
        $this->assertSame($artwork->id, $evidence->subject_id);
    }

    public function test_evidence_subject_relation_resolves(): void
    {
        $artwork  = $this->makeArtwork();
        $evidence = (new AttachEvidence())->execute($artwork, 'provenance');

        $evidence->refresh();
        $this->assertInstanceOf(Artwork::class, $evidence->subject);
        $this->assertSame($artwork->id, $evidence->subject->id);
    }

    public function test_all_eight_evidence_types_accepted(): void
    {
        $artwork = $this->makeArtwork();
        $action  = new AttachEvidence();

        foreach (Evidence::TYPES as $type) {
            $ev = $action->execute($artwork, $type);
            $this->assertSame($type, $ev->type);
        }

        $this->assertSame(8, Evidence::count());
    }

    public function test_unknown_evidence_type_throws(): void
    {
        $artwork = $this->makeArtwork();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown evidence type/');

        (new AttachEvidence())->execute($artwork, 'alien_certificate');
    }

    public function test_evidence_linked_to_impact_project(): void
    {
        $artwork  = $this->makeArtwork();
        $project  = ImpactProject::create(['title' => 'Linked', 'slug' => 'linked']);

        $evidence = (new AttachEvidence())->execute($artwork, 'impact', [
            'impact_project_id' => $project->id,
        ]);

        $this->assertSame($project->id, $evidence->impact_project_id);
        $this->assertInstanceOf(ImpactProject::class, $evidence->impactProject);
    }

    public function test_evidence_verification_lifecycle(): void
    {
        $artwork  = $this->makeArtwork();
        $verifier = User::factory()->create(['role' => 'admin']);

        $evidence = (new AttachEvidence())->execute($artwork, 'condition');
        $this->assertTrue($evidence->isPending());
        $this->assertFalse($evidence->isVerified());

        $evidence->update([
            'verification_status' => 'verified',
            'verified_at'         => now(),
            'verified_by'         => $verifier->id,
        ]);
        $evidence->refresh();

        $this->assertTrue($evidence->isVerified());
        $this->assertNotNull($evidence->verified_at);
        $this->assertSame($verifier->id, $evidence->verified_by);
    }

    public function test_impact_project_has_many_evidence(): void
    {
        $artwork  = $this->makeArtwork();
        $project  = ImpactProject::create(['title' => 'Multi', 'slug' => 'multi']);
        $action   = new AttachEvidence();

        $action->execute($artwork, 'impact',    ['impact_project_id' => $project->id]);
        $action->execute($artwork, 'ownership', ['impact_project_id' => $project->id]);

        $this->assertCount(2, $project->evidence);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeArtwork(): Artwork
    {
        $user = User::factory()->create();
        return Artwork::create([
            'user_id' => $user->id,
            'title'   => 'Test Art '.uniqid(),
            'slug'    => 'test-art-'.uniqid(),
            'status'  => 'listed',
        ]);
    }
}
