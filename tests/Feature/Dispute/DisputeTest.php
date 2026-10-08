<?php

declare(strict_types=1);

namespace Tests\Feature\Dispute;

use App\Domain\Dispute\AssignDispute;
use App\Domain\Dispute\AttachDisputeEvidence;
use App\Domain\Dispute\OpenDispute;
use App\Domain\Dispute\ResolveDispute;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Dispute;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private User   $buyer;
    private User   $admin;
    private ArtLot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['role' => 'buyer']);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $artist     = User::factory()->create();
        $artwork    = Artwork::create(['user_id' => $artist->id, 'title' => 'D', 'slug' => 'd-'.uniqid(), 'status' => 'listed']);
        $this->lot  = ArtLot::create(['artwork_id' => $artwork->id, 'sale_mode' => 'auction', 'status' => 'active', 'currency' => 'EUR']);
    }

    // -----------------------------------------------------------------------
    // OpenDispute
    // -----------------------------------------------------------------------

    public function test_open_dispute_creates_row_and_event(): void
    {
        $dispute = (new OpenDispute())->execute(
            openedBy:    $this->buyer,
            type:        'condition_mismatch',
            description: 'Artwork has a crack not visible in photos.',
            subject:     ['art_lot_id' => $this->lot->id],
        );

        $this->assertSame('open', $dispute->status);
        $this->assertSame('condition_mismatch', $dispute->type);
        $this->assertSame($this->buyer->id, $dispute->opened_by);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'dispute.opened']);
    }

    public function test_open_dispute_requires_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exactly one subject/');

        (new OpenDispute())->execute($this->buyer, 'other', 'No subject.', []);
    }

    public function test_open_dispute_rejects_multiple_subjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new OpenDispute())->execute($this->buyer, 'other', 'Two subjects.', [
            'art_lot_id'      => $this->lot->id,
            'auction_item_id' => 99,
        ]);
    }

    public function test_open_dispute_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown dispute type/');

        (new OpenDispute())->execute($this->buyer, 'alien_complaint', 'desc', ['art_lot_id' => $this->lot->id]);
    }

    public function test_open_dispute_rejects_duplicate_open_dispute(): void
    {
        (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'First.', ['art_lot_id' => $this->lot->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/already exists/');

        (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'Duplicate.', ['art_lot_id' => $this->lot->id]);
    }

    public function test_different_type_can_open_on_same_subject(): void
    {
        (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'crack', ['art_lot_id' => $this->lot->id]);
        (new OpenDispute())->execute($this->buyer, 'payment_dispute',    'charge', ['art_lot_id' => $this->lot->id]);

        $this->assertSame(2, Dispute::count());
    }

    public function test_resolved_dispute_does_not_block_new_open_dispute(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'crack', ['art_lot_id' => $this->lot->id]);

        (new ResolveDispute())->execute($dispute, $this->admin, 'dismissed', 'No evidence.');

        // Now the same type + subject should be allowed again
        $second = (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'crack again', ['art_lot_id' => $this->lot->id]);
        $this->assertSame('open', $second->status);
    }

    // -----------------------------------------------------------------------
    // AssignDispute
    // -----------------------------------------------------------------------

    public function test_assign_sets_status_to_under_review(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'desc', ['art_lot_id' => $this->lot->id]);

        $updated = (new AssignDispute())->execute($dispute, $this->admin);

        $this->assertSame('under_review', $updated->status);
        $this->assertSame($this->admin->id, $updated->assigned_to);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'dispute.assigned']);
    }

    public function test_cannot_assign_resolved_dispute(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'desc', ['art_lot_id' => $this->lot->id]);
        (new ResolveDispute())->execute($dispute, $this->admin, 'resolved', 'done');

        $this->expectException(\DomainException::class);
        (new AssignDispute())->execute($dispute, $this->admin);
    }

    // -----------------------------------------------------------------------
    // ResolveDispute
    // -----------------------------------------------------------------------

    public function test_resolve_sets_status_and_timestamp(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'crack', ['art_lot_id' => $this->lot->id]);

        $resolved = (new ResolveDispute())->execute($dispute, $this->admin, 'resolved', 'Condition accepted by seller.');

        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($this->admin->id, $resolved->resolved_by);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'dispute.resolved']);
    }

    public function test_dismiss_sets_status_dismissed(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'unfounded', ['art_lot_id' => $this->lot->id]);

        $dismissed = (new ResolveDispute())->execute($dispute, $this->admin, 'dismissed', 'No evidence provided.');

        $this->assertSame('dismissed', $dismissed->status);
        $this->assertDatabaseHas('domain_events', ['event_type' => 'dispute.dismissed']);
    }

    public function test_cannot_resolve_already_resolved_dispute(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'desc', ['art_lot_id' => $this->lot->id]);
        (new ResolveDispute())->execute($dispute, $this->admin, 'resolved', 'done');

        $this->expectException(\DomainException::class);
        (new ResolveDispute())->execute($dispute, $this->admin, 'dismissed', 'again');
    }

    public function test_invalid_outcome_throws(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'desc', ['art_lot_id' => $this->lot->id]);

        $this->expectException(\InvalidArgumentException::class);
        (new ResolveDispute())->execute($dispute, $this->admin, 'deleted', 'n/a');
    }

    // -----------------------------------------------------------------------
    // AttachDisputeEvidence
    // -----------------------------------------------------------------------

    public function test_attach_evidence_adds_row(): void
    {
        $dispute  = (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'crack', ['art_lot_id' => $this->lot->id]);
        $evidence = (new AttachDisputeEvidence())->execute($dispute, $this->buyer, 'photo', ['notes' => 'Close-up of crack.']);

        $this->assertSame($dispute->id, $evidence->dispute_id);
        $this->assertSame($this->buyer->id, $evidence->submitted_by);
        $this->assertSame('photo', $evidence->type);
    }

    public function test_attach_evidence_blocked_on_resolved_dispute(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'desc', ['art_lot_id' => $this->lot->id]);
        (new ResolveDispute())->execute($dispute, $this->admin, 'resolved', 'ok');

        $this->expectException(\DomainException::class);
        (new AttachDisputeEvidence())->execute($dispute, $this->buyer, 'photo');
    }

    public function test_multiple_parties_can_attach_evidence(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'condition_mismatch', 'crack', ['art_lot_id' => $this->lot->id]);
        $seller  = User::factory()->create();

        (new AttachDisputeEvidence())->execute($dispute, $this->buyer, 'photo');
        (new AttachDisputeEvidence())->execute($dispute, $seller,       'document', ['notes' => 'Inspection cert.']);
        (new AttachDisputeEvidence())->execute($dispute, $this->admin,  'condition_report');

        $this->assertCount(3, $dispute->evidence);
    }

    // -----------------------------------------------------------------------
    // isResolved / isOpen helpers
    // -----------------------------------------------------------------------

    public function test_is_open_and_is_resolved_helpers(): void
    {
        $dispute = (new OpenDispute())->execute($this->buyer, 'other', 'desc', ['art_lot_id' => $this->lot->id]);

        $this->assertTrue($dispute->isOpen());
        $this->assertFalse($dispute->isResolved());

        (new ResolveDispute())->execute($dispute, $this->admin, 'dismissed', 'n/a');
        $dispute->refresh();

        $this->assertFalse($dispute->isOpen());
        $this->assertTrue($dispute->isResolved());
    }

    public function test_all_six_dispute_types_accepted(): void
    {
        $action = new OpenDispute();
        foreach (Dispute::TYPES as $i => $type) {
            // Each needs a different lot to avoid duplicate-type collision
            $artwork = Artwork::create(['user_id' => $this->buyer->id, 'title' => 'A'.$i, 'slug' => 'a-'.$i.uniqid(), 'status' => 'listed']);
            $lot     = ArtLot::create(['artwork_id' => $artwork->id, 'sale_mode' => 'auction', 'status' => 'active', 'currency' => 'EUR']);
            $d = $action->execute($this->buyer, $type, 'desc', ['art_lot_id' => $lot->id]);
            $this->assertSame($type, $d->type);
        }
    }
}
