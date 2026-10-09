<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Auction;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 89 — Spatie Laravel Activity Log.
 *
 * Verifies automatic model-change tracking on key domain entities.
 * This is separate from AdminAuditLog (explicit admin actions); this
 * layer tracks model lifecycle events automatically.
 */
final class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    // ── Artwork ───────────────────────────────────────────────────────────────

    public function test_artwork_creation_is_logged(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'    => $user->id,
            'title'      => 'First Oil',
            'slug'       => Str::uuid()->toString(),
            'status'     => 'draft',
            'is_original' => true,
        ]);

        $activity = Activity::where('subject_type', Artwork::class)
            ->where('subject_id', $artwork->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity, 'Artwork creation must produce an activity log entry.');
        $this->assertSame('artwork', $activity->log_name);
    }

    public function test_artwork_status_change_is_logged(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'    => $user->id,
            'title'      => 'Status Test',
            'slug'       => Str::uuid()->toString(),
            'status'     => 'draft',
            'is_original' => true,
        ]);

        $artwork->update(['status' => 'listed']);

        $update = Activity::where('subject_type', Artwork::class)
            ->where('subject_id', $artwork->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($update, 'Artwork status update must be logged.');
        $this->assertSame('artwork', $update->log_name);
    }

    public function test_artwork_no_dirty_change_does_not_log(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'    => $user->id,
            'title'      => 'Pristine',
            'slug'       => Str::uuid()->toString(),
            'status'     => 'draft',
            'is_original' => true,
        ]);

        $countBefore = Activity::where('subject_type', Artwork::class)
            ->where('subject_id', $artwork->id)
            ->count();

        // Save without changes — logOnlyDirty + dontSubmitEmptyLogs should suppress
        $artwork->save();

        $countAfter = Activity::where('subject_type', Artwork::class)
            ->where('subject_id', $artwork->id)
            ->count();

        $this->assertSame($countBefore, $countAfter,
            'Saving an unchanged artwork must not produce a new activity entry.');
    }

    // ── ArtLot ────────────────────────────────────────────────────────────────

    public function test_art_lot_status_transition_is_logged(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id'    => $user->id,
            'title'      => 'Lot Test',
            'slug'       => Str::uuid()->toString(),
            'status'     => 'draft',
            'is_original' => true,
        ]);
        $gallery = Gallery::create([
            'name'    => 'Test Gallery',
            'slug'    => Str::uuid()->toString(),
            'type'    => 'private',
            'user_id' => $user->id,
        ]);
        $lot = ArtLot::create([
            'artwork_id' => $artwork->id,
            'gallery_id' => $gallery->id,
            'status'     => 'draft',
            'currency'   => 'EUR',
        ]);

        $lot->update(['status' => 'active']);

        $activity = Activity::where('subject_type', ArtLot::class)
            ->where('subject_id', $lot->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity, 'ArtLot status change must be logged.');
    }

    // ── Gallery ───────────────────────────────────────────────────────────────

    public function test_gallery_creation_is_logged(): void
    {
        $user    = User::factory()->create(['role' => 'artist']);
        $gallery = Gallery::create([
            'name'    => 'New Gallery',
            'slug'    => Str::uuid()->toString(),
            'type'    => 'private',
            'user_id' => $user->id,
        ]);

        $activity = Activity::where('subject_type', Gallery::class)
            ->where('subject_id', $gallery->id)
            ->first();

        $this->assertNotNull($activity, 'Gallery creation must be logged.');
        $this->assertSame('gallery', $activity->log_name);
    }

    // ── Log isolation ─────────────────────────────────────────────────────────

    public function test_activity_log_entries_reference_correct_model(): void
    {
        $user = User::factory()->create(['role' => 'artist']);
        $a1   = Artwork::create(['user_id' => $user->id, 'title' => 'A1', 'slug' => Str::uuid()->toString(), 'status' => 'draft', 'is_original' => true]);
        $a2   = Artwork::create(['user_id' => $user->id, 'title' => 'A2', 'slug' => Str::uuid()->toString(), 'status' => 'draft', 'is_original' => true]);

        $a1->update(['status' => 'listed']);

        $a1Logs = Activity::where('subject_type', Artwork::class)->where('subject_id', $a1->id)->count();
        $a2Logs = Activity::where('subject_type', Artwork::class)->where('subject_id', $a2->id)->count();

        $this->assertGreaterThan(0, $a1Logs);
        // a2 had creation logged but no update
        $this->assertSame(1, $a2Logs, 'a2 must have exactly 1 log entry (creation).');
    }
}
