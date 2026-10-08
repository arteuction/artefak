<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\TransitionArtLot;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArtLotLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $status = 'draft'): ArtLot
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create(['user_id' => $user->id, 'title' => 'T', 'slug' => 'sl-' . uniqid(), 'status' => 'listed']);

        return ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $user->id,
            'sale_mode'    => 'sell_now',
            'status'       => $status,
            'currency'     => 'EUR',
        ]);
    }

    public function test_submit_advances_draft_to_submitted(): void
    {
        $lot    = $this->lot('draft');
        $action = new TransitionArtLot();

        $result = $action->execute($lot, 'submit');

        $this->assertSame('submitted', $result->status);
    }

    public function test_verify_advances_submitted_to_verification(): void
    {
        $lot    = $this->lot('submitted');
        $result = (new TransitionArtLot())->execute($lot, 'verify');

        $this->assertSame('verification', $result->status);
    }

    public function test_approve_advances_verification_to_approved(): void
    {
        $lot    = $this->lot('verification');
        $result = (new TransitionArtLot())->execute($lot, 'approve');

        $this->assertSame('approved', $result->status);
    }

    public function test_catalogue_advances_approved_to_catalogued(): void
    {
        $lot    = $this->lot('approved');
        $result = (new TransitionArtLot())->execute($lot, 'catalogue');

        $this->assertSame('catalogued', $result->status);
    }

    public function test_schedule_advances_catalogued_to_scheduled(): void
    {
        $lot    = $this->lot('catalogued');
        $result = (new TransitionArtLot())->execute($lot, 'schedule');

        $this->assertSame('scheduled', $result->status);
    }

    public function test_activate_advances_scheduled_to_active(): void
    {
        $lot    = $this->lot('scheduled');
        $result = (new TransitionArtLot())->execute($lot, 'activate');

        $this->assertSame('active', $result->status);
    }

    public function test_activate_from_approved_is_allowed(): void
    {
        $lot    = $this->lot('approved');
        $result = (new TransitionArtLot())->execute($lot, 'activate');

        $this->assertSame('active', $result->status);
    }

    public function test_invalid_transition_throws(): void
    {
        $this->expectException(\DomainException::class);
        (new TransitionArtLot())->execute($this->lot('draft'), 'approve');
    }

    public function test_unknown_transition_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TransitionArtLot())->execute($this->lot('draft'), 'nonexistent');
    }

    public function test_transition_is_idempotent_at_target_status(): void
    {
        $lot    = $this->lot('submitted');
        $result = (new TransitionArtLot())->execute($lot, 'submit');

        $this->assertSame('submitted', $result->status);
    }
}
