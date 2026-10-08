<?php

declare(strict_types=1);

namespace Tests\Feature\Collection;

use App\Domain\Collection\AddArtworkToCollection;
use App\Domain\Collection\CreateCollection;
use App\Domain\Collection\RemoveArtworkFromCollection;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CollectionTest extends TestCase
{
    use RefreshDatabase;

    private User    $owner;
    private User    $other;
    private Artwork $artwork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner   = User::factory()->create();
        $this->other   = User::factory()->create();
        $this->artwork = Artwork::create([
            'user_id' => $this->owner->id,
            'title'   => 'Test Piece',
            'slug'    => 'test-piece',
            'status'  => 'listed',
        ]);
    }

    public function test_create_collection_default_private(): void
    {
        $col = (new CreateCollection())->execute(
            owner:  $this->owner,
            title:  'My Bulgarian Collection',
            slug:   'my-bulgarian-collection',
        );

        $this->assertSame('private', $col->visibility);
        $this->assertSame($this->owner->id, $col->owner_id);
    }

    public function test_create_collection_public_visibility(): void
    {
        $col = (new CreateCollection())->execute(
            owner:      $this->owner,
            title:      'Public Art Walk',
            slug:       'public-art-walk',
            visibility: 'public',
        );

        $this->assertTrue($col->isPublic());
    }

    public function test_create_collection_invalid_visibility_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CreateCollection())->execute(
            owner:      $this->owner,
            title:      'Bad',
            slug:       'bad',
            visibility: 'secret',
        );
    }

    public function test_add_artwork_to_collection(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'C', 'c-slug');

        (new AddArtworkToCollection())->execute($col, $this->artwork, $this->owner);

        $this->assertSame(1, $col->artworks()->count());
    }

    public function test_add_artwork_idempotent(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'C', 'c-slug-2');

        (new AddArtworkToCollection())->execute($col, $this->artwork, $this->owner);
        (new AddArtworkToCollection())->execute($col, $this->artwork, $this->owner); // no-op

        $this->assertSame(1, $col->artworks()->count());
    }

    public function test_non_owner_cannot_add_artwork(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'C', 'c-slug-3');

        $this->expectException(\DomainException::class);

        (new AddArtworkToCollection())->execute($col, $this->artwork, $this->other);
    }

    public function test_remove_artwork_from_collection(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'C', 'c-slug-4');
        (new AddArtworkToCollection())->execute($col, $this->artwork, $this->owner);

        (new RemoveArtworkFromCollection())->execute($col, $this->artwork, $this->owner);

        $this->assertSame(0, $col->artworks()->count());
    }

    public function test_remove_artwork_idempotent(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'C', 'c-slug-5');

        // Remove something that was never added — should not throw
        (new RemoveArtworkFromCollection())->execute($col, $this->artwork, $this->owner);

        $this->assertSame(0, $col->artworks()->count());
    }

    public function test_collection_can_hold_multiple_artworks_in_order(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'Multi', 'multi-col');

        $a2 = Artwork::create(['user_id' => $this->owner->id, 'title' => 'B', 'slug' => 'b', 'status' => 'listed']);
        $a3 = Artwork::create(['user_id' => $this->owner->id, 'title' => 'C', 'slug' => 'cc', 'status' => 'listed']);

        (new AddArtworkToCollection())->execute($col, $this->artwork, $this->owner, position: 2);
        (new AddArtworkToCollection())->execute($col, $a2,           $this->owner, position: 1);
        (new AddArtworkToCollection())->execute($col, $a3,           $this->owner, position: 0);

        $ids = $col->artworks()->pluck('artworks.id')->toArray();
        // Ordered by position ASC (0, 1, 2)
        $this->assertSame([$a3->id, $a2->id, $this->artwork->id], $ids);
    }

    public function test_collection_does_not_affect_ownership(): void
    {
        $col = (new CreateCollection())->execute($this->owner, 'Wish', 'wish-list');

        // Add an artwork owned by someone else — wish-list scenario
        $other_artwork = Artwork::create([
            'user_id' => $this->other->id,
            'title'   => 'Other Piece',
            'slug'    => 'other-piece',
            'status'  => 'listed',
        ]);

        (new AddArtworkToCollection())->execute($col, $other_artwork, $this->owner);

        // Collection exists; artwork owner unchanged
        $this->assertSame($this->other->id, $other_artwork->fresh()->user_id);
        $this->assertSame(1, $col->artworks()->count());
    }

    public function test_all_visibilities_are_valid(): void
    {
        foreach (Collection::VISIBILITIES as $i => $vis) {
            $col = (new CreateCollection())->execute($this->owner, "Col {$i}", "col-{$i}", $vis);
            $this->assertSame($vis, $col->visibility);
        }
    }
}
