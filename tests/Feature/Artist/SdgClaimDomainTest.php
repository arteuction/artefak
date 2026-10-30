<?php

declare(strict_types=1);

namespace Tests\Feature\Artist;

use App\Domain\Artist\ReviewSdgClaim;
use App\Domain\Artist\SubmitSdgClaim;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests for SubmitSdgClaim and ReviewSdgClaim domain actions.
 */
final class SdgClaimDomainTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function artwork(User $artist): Artwork
    {
        return Artwork::create([
            'user_id'     => $artist->id,
            'title'       => 'Art ' . uniqid(),
            'slug'        => 'art-' . uniqid(),
            'status'      => 'listed',
            'is_original' => true,
        ]);
    }

    // ── SubmitSdgClaim ───────────────────────────────────────────────────────

    public function test_submit_creates_pending_sdg_claim(): void
    {
        $artwork = $this->artwork($this->artist());

        $claim = (new SubmitSdgClaim())->execute($artwork, 4, 'Supports quality education.');

        $this->assertInstanceOf(ArtworkSdgClaim::class, $claim);
        $this->assertSame(4, $claim->sdg_number);
        $this->assertSame('pending', $claim->status);
        $this->assertSame('Supports quality education.', $claim->rationale);
    }

    public function test_submit_rejects_sdg_number_below_1(): void
    {
        $artwork = $this->artwork($this->artist());

        $this->expectException(InvalidArgumentException::class);

        (new SubmitSdgClaim())->execute($artwork, 0, 'Invalid SDG number.');
    }

    public function test_submit_rejects_sdg_number_above_17(): void
    {
        $artwork = $this->artwork($this->artist());

        $this->expectException(InvalidArgumentException::class);

        (new SubmitSdgClaim())->execute($artwork, 18, 'Invalid SDG number.');
    }

    public function test_submit_allows_all_valid_sdg_numbers(): void
    {
        $artist = $this->artist();

        for ($sdg = 1; $sdg <= 17; $sdg++) {
            $artwork = $this->artwork($artist);
            $claim = (new SubmitSdgClaim())->execute($artwork, $sdg, "Rationale for SDG {$sdg}.");
            $this->assertSame($sdg, $claim->sdg_number);
        }
    }

    public function test_submit_resets_previously_reviewed_claim_to_pending(): void
    {
        $artist  = $this->artist();
        $admin   = $this->admin();
        $artwork = $this->artwork($artist);

        $claim = (new SubmitSdgClaim())->execute($artwork, 7, 'Clean energy.');
        $claim->update([
            'status'      => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        // Re-submit resets to pending
        (new SubmitSdgClaim())->execute($artwork, 7, 'Updated rationale.');

        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->reviewed_by);
    }

    public function test_submit_is_upsert_for_same_artwork_and_sdg(): void
    {
        $artwork = $this->artwork($this->artist());

        (new SubmitSdgClaim())->execute($artwork, 5, 'First rationale.');
        (new SubmitSdgClaim())->execute($artwork, 5, 'Updated rationale.');

        $this->assertSame(
            1,
            ArtworkSdgClaim::where('artwork_id', $artwork->id)
                ->where('sdg_number', 5)
                ->count()
        );
    }

    // ── ReviewSdgClaim ───────────────────────────────────────────────────────

    public function test_approve_sets_approved_status(): void
    {
        $artwork = $this->artwork($this->artist());
        $admin   = $this->admin();

        $claim = (new SubmitSdgClaim())->execute($artwork, 13, 'Climate action.');

        (new ReviewSdgClaim())->approve($claim, $admin, 'Well documented.');

        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSame($admin->id, $claim->fresh()->reviewed_by);
    }

    public function test_reject_sets_rejected_status_with_note(): void
    {
        $artwork = $this->artwork($this->artist());
        $admin   = $this->admin();

        $claim = (new SubmitSdgClaim())->execute($artwork, 1, 'No poverty.');

        (new ReviewSdgClaim())->reject($claim, $admin, 'Insufficient evidence.');

        $this->assertSame('rejected', $claim->fresh()->status);
        $this->assertSame('Insufficient evidence.', $claim->fresh()->review_note);
    }

    public function test_approve_throws_for_non_pending_claim(): void
    {
        $artwork = $this->artwork($this->artist());
        $admin   = $this->admin();

        $claim = (new SubmitSdgClaim())->execute($artwork, 2, 'Zero hunger.');
        (new ReviewSdgClaim())->approve($claim, $admin);

        $this->expectException(InvalidArgumentException::class);

        // Cannot approve an already-approved claim
        (new ReviewSdgClaim())->approve($claim->fresh(), $admin);
    }

    public function test_reject_throws_for_non_pending_claim(): void
    {
        $artwork = $this->artwork($this->artist());
        $admin   = $this->admin();

        $claim = (new SubmitSdgClaim())->execute($artwork, 3, 'Good health.');
        (new ReviewSdgClaim())->reject($claim, $admin, 'Not enough.');

        $this->expectException(InvalidArgumentException::class);

        (new ReviewSdgClaim())->reject($claim->fresh(), $admin, 'Still not enough.');
    }
}
