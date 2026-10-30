<?php

declare(strict_types=1);

namespace Tests\Feature\Gallery;

use App\Domain\Gallery\AddGalleryStaff;
use App\Domain\Gallery\RevokeGalleryStaff;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Unit tests for AddGalleryStaff and RevokeGalleryStaff domain actions.
 */
final class GalleryStaffDomainTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['role' => 'artist']);
    }

    private function gallery(): Gallery
    {
        return Gallery::create([
            'name'   => 'G ' . uniqid(),
            'slug'   => 'g-' . uniqid(),
            'status' => 'active',
            'type'   => 'private',
        ]);
    }

    private function addOwner(Gallery $gallery, User $user): GalleryStaff
    {
        return GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $user->id,
            'role'        => 'owner',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AddGalleryStaff
    // ════════════════════════════════════════════════════════════════════════

    public function test_add_gallery_staff_creates_staff_row(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $member  = $this->artist();
        $this->addOwner($gallery, $owner);

        $staff = (new AddGalleryStaff())->execute($gallery, $member, 'finance', $owner);

        $this->assertSame('active', $staff->status);
        $this->assertSame('finance', $staff->role);
        $this->assertDatabaseHas('gallery_staff', [
            'gallery_id' => $gallery->id,
            'user_id'    => $member->id,
            'role'       => 'finance',
            'status'     => 'active',
        ]);
    }

    public function test_add_gallery_staff_rejects_invalid_role(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $member  = $this->artist();
        $this->addOwner($gallery, $owner);

        $this->expectException(InvalidArgumentException::class);

        (new AddGalleryStaff())->execute($gallery, $member, 'superadmin', $owner);
    }

    public function test_add_gallery_staff_rejects_non_owner_inviter(): void
    {
        $gallery     = $this->gallery();
        $notAnOwner  = $this->artist();
        $member      = $this->artist();

        // notAnOwner has no owner role at this gallery
        $this->expectException(DomainException::class);

        (new AddGalleryStaff())->execute($gallery, $member, 'staff', $notAnOwner);
    }

    public function test_add_gallery_staff_is_idempotent_for_active_staff(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $member  = $this->artist();
        $this->addOwner($gallery, $owner);

        (new AddGalleryStaff())->execute($gallery, $member, 'sales', $owner);
        (new AddGalleryStaff())->execute($gallery, $member, 'sales', $owner); // second call

        $this->assertSame(
            1,
            GalleryStaff::where('gallery_id', $gallery->id)
                ->where('user_id', $member->id)
                ->where('role', 'sales')
                ->count()
        );
    }

    public function test_add_gallery_staff_reactivates_revoked_row(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $member  = $this->artist();
        $this->addOwner($gallery, $owner);

        // Create a revoked row
        GalleryStaff::create([
            'gallery_id' => $gallery->id,
            'user_id'    => $member->id,
            'role'       => 'curator',
            'status'     => 'revoked',
        ]);

        $result = (new AddGalleryStaff())->execute($gallery, $member, 'curator', $owner);

        $this->assertSame('active', $result->status);
        $this->assertSame(
            1,
            GalleryStaff::where('gallery_id', $gallery->id)
                ->where('user_id', $member->id)
                ->where('role', 'curator')
                ->count()
        );
    }

    public function test_add_gallery_staff_allows_all_valid_roles(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $this->addOwner($gallery, $owner);

        foreach (GalleryStaff::ROLES as $role) {
            $member = $this->artist();
            $result = (new AddGalleryStaff())->execute($gallery, $member, $role, $owner);
            $this->assertSame($role, $result->role);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // RevokeGalleryStaff
    // ════════════════════════════════════════════════════════════════════════

    public function test_revoke_gallery_staff_marks_row_revoked(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $member  = $this->artist();
        $this->addOwner($gallery, $owner);

        GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $member->id,
            'role'        => 'finance',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        (new RevokeGalleryStaff())->execute($gallery, $member, 'finance', $owner);

        $this->assertDatabaseHas('gallery_staff', [
            'gallery_id' => $gallery->id,
            'user_id'    => $member->id,
            'role'       => 'finance',
            'status'     => 'revoked',
        ]);
    }

    public function test_revoke_gallery_staff_rejects_non_owner_revoker(): void
    {
        $gallery     = $this->gallery();
        $notAnOwner  = $this->artist();
        $member      = $this->artist();

        $this->expectException(DomainException::class);

        (new RevokeGalleryStaff())->execute($gallery, $member, 'staff', $notAnOwner);
    }

    public function test_revoke_gallery_staff_prevents_removing_last_owner(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $this->addOwner($gallery, $owner);

        // Only one owner → cannot self-revoke
        $this->expectException(DomainException::class);

        (new RevokeGalleryStaff())->execute($gallery, $owner, 'owner', $owner);
    }

    public function test_revoke_gallery_staff_allows_removing_owner_when_another_owner_exists(): void
    {
        $gallery = $this->gallery();
        $owner1  = $this->artist();
        $owner2  = $this->artist();
        $this->addOwner($gallery, $owner1);
        $this->addOwner($gallery, $owner2);

        (new RevokeGalleryStaff())->execute($gallery, $owner2, 'owner', $owner1);

        $this->assertDatabaseHas('gallery_staff', [
            'gallery_id' => $gallery->id,
            'user_id'    => $owner2->id,
            'role'       => 'owner',
            'status'     => 'revoked',
        ]);
    }

    public function test_revoke_gallery_staff_is_idempotent_for_non_existent_row(): void
    {
        $gallery = $this->gallery();
        $owner   = $this->artist();
        $member  = $this->artist();
        $this->addOwner($gallery, $owner);

        // member was never added → no-op (no exception)
        (new RevokeGalleryStaff())->execute($gallery, $member, 'finance', $owner);

        $this->assertDatabaseMissing('gallery_staff', [
            'gallery_id' => $gallery->id,
            'user_id'    => $member->id,
        ]);
    }
}
