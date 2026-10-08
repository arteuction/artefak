<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Gallery\AddGalleryStaff;
use App\Domain\Gallery\RevokeGalleryStaff;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GalleryStaffTest extends TestCase
{
    use RefreshDatabase;

    private Gallery $gallery;
    private User    $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner   = User::factory()->create();
        $this->gallery = Gallery::create(['name' => 'Test Gallery', 'slug' => 'test-gallery', 'status' => 'active']);

        // Seed the owner directly (no invitation needed for the first owner)
        GalleryStaff::create([
            'gallery_id' => $this->gallery->id,
            'user_id'    => $this->owner->id,
            'role'       => 'owner',
            'status'     => 'active',
        ]);
    }

    public function test_owner_can_add_staff_with_valid_role(): void
    {
        $newUser = User::factory()->create();

        $staff = (new AddGalleryStaff())->execute(
            gallery:   $this->gallery,
            user:      $newUser,
            role:      'curator',
            invitedBy: $this->owner,
        );

        $this->assertSame('curator', $staff->role);
        $this->assertSame('active', $staff->status);
        $this->assertSame($this->owner->id, $staff->invited_by);
    }

    public function test_add_staff_is_idempotent_for_active_role(): void
    {
        $newUser = User::factory()->create();

        (new AddGalleryStaff())->execute($this->gallery, $newUser, 'sales', $this->owner);
        $first = GalleryStaff::where('gallery_id', $this->gallery->id)->where('user_id', $newUser->id)->count();

        (new AddGalleryStaff())->execute($this->gallery, $newUser, 'sales', $this->owner);
        $second = GalleryStaff::where('gallery_id', $this->gallery->id)->where('user_id', $newUser->id)->count();

        $this->assertSame($first, $second);
    }

    public function test_add_staff_reactivates_revoked_row(): void
    {
        $newUser = User::factory()->create();
        (new AddGalleryStaff())->execute($this->gallery, $newUser, 'finance', $this->owner);

        GalleryStaff::where('gallery_id', $this->gallery->id)
            ->where('user_id', $newUser->id)
            ->update(['status' => 'revoked']);

        $reactivated = (new AddGalleryStaff())->execute($this->gallery, $newUser, 'finance', $this->owner);

        $this->assertSame('active', $reactivated->status);
        $this->assertSame(1, GalleryStaff::where('gallery_id', $this->gallery->id)->where('user_id', $newUser->id)->count());
    }

    public function test_non_owner_cannot_add_staff(): void
    {
        $nonOwner = User::factory()->create();
        $newUser  = User::factory()->create();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/not an owner/i');

        (new AddGalleryStaff())->execute($this->gallery, $newUser, 'curator', $nonOwner);
    }

    public function test_invalid_role_throws_invalid_argument(): void
    {
        $newUser = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        (new AddGalleryStaff())->execute($this->gallery, $newUser, 'janitor', $this->owner);
    }

    public function test_all_valid_roles_can_be_assigned(): void
    {
        foreach (GalleryStaff::ROLES as $role) {
            $u = User::factory()->create();
            $staff = (new AddGalleryStaff())->execute($this->gallery, $u, $role, $this->owner);
            $this->assertSame($role, $staff->role);
        }
    }

    public function test_owner_can_revoke_staff(): void
    {
        $curator = User::factory()->create();
        (new AddGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner);

        (new RevokeGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner);

        $this->assertDatabaseHas('gallery_staff', [
            'gallery_id' => $this->gallery->id,
            'user_id'    => $curator->id,
            'role'       => 'curator',
            'status'     => 'revoked',
        ]);
    }

    public function test_revoke_is_idempotent(): void
    {
        $curator = User::factory()->create();
        (new AddGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner);

        (new RevokeGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner);
        (new RevokeGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner); // no-op

        $this->assertSame(1, GalleryStaff::where('status', 'revoked')->count());
    }

    public function test_cannot_revoke_last_owner(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/last owner/i');

        (new RevokeGalleryStaff())->execute($this->gallery, $this->owner, 'owner', $this->owner);
    }

    public function test_gallery_has_member_helper(): void
    {
        $curator = User::factory()->create();
        $this->assertFalse($this->gallery->hasMember($curator));

        (new AddGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner);
        $this->assertTrue($this->gallery->hasMember($curator));
    }

    public function test_gallery_has_role_helper(): void
    {
        $finance = User::factory()->create();
        (new AddGalleryStaff())->execute($this->gallery, $finance, 'finance', $this->owner);

        $this->assertTrue($this->gallery->hasRole($finance, 'finance'));
        $this->assertFalse($this->gallery->hasRole($finance, 'curator'));
    }

    public function test_active_staff_relation_excludes_revoked(): void
    {
        $curator = User::factory()->create();
        $sales   = User::factory()->create();

        (new AddGalleryStaff())->execute($this->gallery, $curator, 'curator', $this->owner);
        (new AddGalleryStaff())->execute($this->gallery, $sales, 'sales', $this->owner);
        (new RevokeGalleryStaff())->execute($this->gallery, $sales, 'sales', $this->owner);

        // owner + curator = 2 active; sales revoked
        $this->assertSame(2, $this->gallery->activeStaff()->count());
        $this->assertSame(3, $this->gallery->staff()->count());
    }
}
