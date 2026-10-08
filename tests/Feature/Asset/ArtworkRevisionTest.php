<?php

declare(strict_types=1);

namespace Tests\Feature\Asset;

use App\Domain\Asset\CreateArtworkRevision;
use App\Domain\Asset\PublishArtworkRevision;
use App\Models\Artwork;
use App\Models\ArtworkRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class ArtworkRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User    $artist;
    private User    $admin;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist  = User::factory()->create(['role' => 'artist']);
        $this->admin   = User::factory()->create(['role' => 'admin']);
        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Test Artwork',
            'slug'    => 'test-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);
    }

    public function test_create_initial_revision_assigns_version_1(): void
    {
        $revision = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Sunset Over Sofia',
            reason: 'initial',
        );

        $this->assertSame(1, $revision->version);
        $this->assertSame('draft', $revision->status);
        $this->assertSame('initial', $revision->reason);
        $this->assertNull($revision->effective_from);
    }

    public function test_second_revision_increments_version(): void
    {
        (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'First Title',
            reason: 'initial',
        );

        $second = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->admin,
            title: 'Corrected Title',
            reason: 'correction',
        );

        $this->assertSame(2, $second->version);
    }

    public function test_create_revision_stores_all_fields(): void
    {
        $revision = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Blue Period',
            reason: 'initial',
            yearCreated: 2021,
            medium: 'oil on canvas',
            dimensionsNotes: '60 × 80 cm',
            description: 'A blue composition.',
            editionInfo: 'unique',
        );

        $this->assertSame('Blue Period', $revision->title);
        $this->assertSame(2021, $revision->year_created);
        $this->assertSame('oil on canvas', $revision->medium);
        $this->assertSame('60 × 80 cm', $revision->dimensions_notes);
        $this->assertSame('unique', $revision->edition_info);
        $this->assertSame($this->artist->id, $revision->revised_by);
    }

    public function test_create_revision_rejects_invalid_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid revision reason');

        (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Bad Reason',
            reason: 'typo_fix',
        );
    }

    public function test_publish_revision_sets_active_and_effective_from(): void
    {
        $revision = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Ready',
            reason: 'initial',
        );

        $published = (new PublishArtworkRevision())->execute($revision);

        $this->assertSame('active', $published->status);
        $this->assertNotNull($published->effective_from);
        $this->assertTrue($published->isActive());
    }

    public function test_publish_supersedes_previous_active_revision(): void
    {
        $first = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Version One',
            reason: 'initial',
        );
        (new PublishArtworkRevision())->execute($first);

        $second = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->admin,
            title: 'Version Two',
            reason: 'correction',
        );
        (new PublishArtworkRevision())->execute($second);

        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_only_one_active_revision_per_artwork(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $rev = (new CreateArtworkRevision())->execute(
                artwork: $this->artwork,
                revisedBy: $this->artist,
                title: "Version {$i}",
                reason: $i === 1 ? 'initial' : 'correction',
            );
            (new PublishArtworkRevision())->execute($rev);
        }

        $activeCount = ArtworkRevision::where('artwork_id', $this->artwork->id)
            ->where('status', 'active')
            ->count();

        $this->assertSame(1, $activeCount);
    }

    public function test_publish_draft_that_is_already_active_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot publish revision with status 'active'");

        $revision = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Already Active',
            reason: 'initial',
        );
        (new PublishArtworkRevision())->execute($revision);
        (new PublishArtworkRevision())->execute($revision->fresh()); // active again -> throws
    }

    public function test_artwork_revisions_relation_ordered_by_version(): void
    {
        for ($i = 0; $i < 3; $i++) {
            (new CreateArtworkRevision())->execute(
                artwork: $this->artwork,
                revisedBy: $this->artist,
                title: "Title {$i}",
                reason: $i === 0 ? 'initial' : 'correction',
            );
        }

        $versions = $this->artwork->revisions->pluck('version')->toArray();

        $this->assertSame([1, 2, 3], $versions);
    }

    public function test_revisions_from_different_artworks_have_independent_version_sequences(): void
    {
        $artwork2 = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Second Artwork',
            'slug'    => 'second-artwork-' . uniqid(),
            'status'  => 'listed',
        ]);

        $rev1 = (new CreateArtworkRevision())->execute(
            artwork: $this->artwork,
            revisedBy: $this->artist,
            title: 'Art1 v1',
            reason: 'initial',
        );

        $rev2 = (new CreateArtworkRevision())->execute(
            artwork: $artwork2,
            revisedBy: $this->artist,
            title: 'Art2 v1',
            reason: 'initial',
        );

        $this->assertSame(1, $rev1->version);
        $this->assertSame(1, $rev2->version);
    }
}
