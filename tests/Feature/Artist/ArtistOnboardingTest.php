<?php

declare(strict_types=1);

namespace Tests\Feature\Artist;

use App\Domain\Artist\ReviewApplication;
use App\Domain\Artist\SubmitApplication;
use App\Domain\Artist\SubmitSdgClaim;
use App\Domain\Artist\ReviewSdgClaim;
use App\Models\ArtistApplication;
use App\Models\ArtistProfile;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ArtistOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $artist;
    private User $admin;
    private ArtistProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist = User::factory()->create(['role' => 'artist']);
        $this->admin  = User::factory()->create(['role' => 'admin']);

        $this->profile = ArtistProfile::create([
            'user_id'      => $this->artist->id,
            'display_name' => 'Ivan Ivanov',
            'slug'         => 'ivan-ivanov',
        ]);
    }

    // ── SubmitApplication ─────────────────────────────────────────

    public function test_artist_can_submit_application(): void
    {
        $application = (new SubmitApplication())->execute(
            profile:      $this->profile,
            portfolioUrl: 'https://ivanivanov.art',
            motivation:   'My art connects communities.',
        );

        $this->assertSame('submitted', $application->status);
        $this->assertSame(1, $application->version);
        $this->assertDatabaseHas('artist_applications', [
            'artist_profile_id' => $this->profile->id,
            'status'            => 'submitted',
        ]);
    }

    public function test_submitting_application_moves_profile_to_under_review(): void
    {
        (new SubmitApplication())->execute($this->profile);

        $this->assertSame('under_review', $this->profile->fresh()->status);
    }

    public function test_resubmission_increments_version(): void
    {
        (new SubmitApplication())->execute($this->profile);
        $second = (new SubmitApplication())->execute($this->profile);

        $this->assertSame(2, $second->version);
    }

    public function test_submission_writes_audit_log(): void
    {
        (new SubmitApplication())->execute($this->profile);

        $this->assertDatabaseHas('admin_audit_log', [
            'actor_id' => $this->artist->id,
            'action'   => 'application.submitted',
        ]);
    }

    // ── ReviewApplication ─────────────────────────────────────────

    public function test_admin_can_approve_application(): void
    {
        $application = (new SubmitApplication())->execute($this->profile);

        (new ReviewApplication())->approve($application, $this->admin, 'Great portfolio.');

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame('approved', $this->profile->fresh()->status);
    }

    public function test_approval_writes_audit_log(): void
    {
        $application = (new SubmitApplication())->execute($this->profile);
        (new ReviewApplication())->approve($application, $this->admin);

        $this->assertDatabaseHas('admin_audit_log', [
            'actor_id' => $this->admin->id,
            'action'   => 'artist.approved',
        ]);
    }

    public function test_admin_can_reject_application(): void
    {
        $application = (new SubmitApplication())->execute($this->profile);

        (new ReviewApplication())->reject($application, $this->admin, 'Incomplete documents.');

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertSame('rejected', $this->profile->fresh()->status);
    }

    public function test_cannot_review_already_reviewed_application(): void
    {
        $application = (new SubmitApplication())->execute($this->profile);
        (new ReviewApplication())->approve($application, $this->admin);

        $this->expectException(InvalidArgumentException::class);

        (new ReviewApplication())->reject($application->fresh(), $this->admin, 'Changed my mind.');
    }

    // ── SubmitSdgClaim ────────────────────────────────────────────

    public function test_artist_can_submit_sdg_claim(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Green Future',
            'slug'    => 'green-future',
            'status'  => 'draft',
        ]);

        $claim = (new SubmitSdgClaim())->execute(
            artwork:    $artwork,
            sdgNumber:  13,
            rationale:  'This artwork depicts climate adaptation.',
            evidence:   'https://climate-report.org/exhibit-a',
        );

        $this->assertSame('pending', $claim->status);
        $this->assertSame(13, $claim->sdg_number);
        $this->assertDatabaseHas('artwork_sdg_claims', [
            'artwork_id' => $artwork->id,
            'sdg_number' => 13,
            'status'     => 'pending',
        ]);
    }

    public function test_sdg_claim_resubmission_resets_to_pending(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Ocean Life',
            'slug'    => 'ocean-life',
            'status'  => 'draft',
        ]);

        $claim = (new SubmitSdgClaim())->execute($artwork, 14, 'Original rationale.');
        (new ReviewSdgClaim())->approve($claim, $this->admin);

        // Re-submit resets to pending
        (new SubmitSdgClaim())->execute($artwork, 14, 'Updated rationale.');

        $this->assertSame('pending', $artwork->sdgClaims()->first()->status);
        $this->assertDatabaseCount('artwork_sdg_claims', 1);
    }

    public function test_invalid_sdg_number_throws(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'X',
            'slug'    => 'x',
            'status'  => 'draft',
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new SubmitSdgClaim())->execute($artwork, 18, 'Invalid SDG.');
    }

    // ── ReviewSdgClaim ────────────────────────────────────────────

    public function test_admin_can_approve_sdg_claim(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Forest',
            'slug'    => 'forest',
            'status'  => 'draft',
        ]);
        $claim = (new SubmitSdgClaim())->execute($artwork, 15, 'Protects biodiversity.');

        (new ReviewSdgClaim())->approve($claim, $this->admin);

        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'sdg_claim.approved']);
    }

    public function test_cannot_review_already_reviewed_sdg_claim(): void
    {
        $artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Peace',
            'slug'    => 'peace',
            'status'  => 'draft',
        ]);
        $claim = (new SubmitSdgClaim())->execute($artwork, 16, 'Peace rationale.');
        (new ReviewSdgClaim())->approve($claim, $this->admin);

        $this->expectException(InvalidArgumentException::class);

        (new ReviewSdgClaim())->reject($claim->fresh(), $this->admin, 'Nope.');
    }
}
