<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Models\SplitProfileVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SplitProfileVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_migration_creates_two_canonical_profiles(): void
    {
        $this->assertDatabaseCount('split_profiles', 2);
        $this->assertDatabaseHas('split_profiles', ['profile_key' => 'social_pilot_45_45_10', 'version' => 1, 'status' => 'active']);
        $this->assertDatabaseHas('split_profiles', ['profile_key' => 'library_80_10_10',      'version' => 1, 'status' => 'active']);
    }

    public function test_canonical_profiles_sum_to_10000_bps(): void
    {
        foreach (SplitProfileVersion::all() as $profile) {
            $this->assertSame(10000, $profile->sumBps(), "Profile {$profile->profile_key} v{$profile->version} does not sum to 10000 bps");
        }
    }

    public function test_active_for_key_returns_active_profile(): void
    {
        $profile = SplitProfileVersion::activeForKey('social_pilot_45_45_10');

        $this->assertNotNull($profile);
        $this->assertSame(4500, $profile->artist_bps);
        $this->assertSame(4500, $profile->fund_bps);
        $this->assertSame(1000, $profile->ops_bps);
    }

    public function test_new_version_can_be_inserted(): void
    {
        // Supersede v1
        SplitProfileVersion::where('profile_key', 'social_pilot_45_45_10')->update([
            'status'         => 'superseded',
            'effective_until'=> now(),
        ]);

        // Insert v2 with a new split
        SplitProfileVersion::create([
            'profile_key'    => 'social_pilot_45_45_10',
            'version'        => 2,
            'artist_bps'     => 5000,
            'fund_bps'       => 4000,
            'ops_bps'        => 1000,
            'status'         => 'active',
            'effective_from' => now(),
        ]);

        $active = SplitProfileVersion::activeForKey('social_pilot_45_45_10');
        $this->assertSame(2, $active->version);
        $this->assertSame(5000, $active->artist_bps);
    }

    public function test_superseded_version_still_exists_for_history(): void
    {
        SplitProfileVersion::where('profile_key', 'social_pilot_45_45_10')->update(['status' => 'superseded']);

        SplitProfileVersion::create([
            'profile_key'    => 'social_pilot_45_45_10',
            'version'        => 2,
            'artist_bps'     => 5000,
            'fund_bps'       => 4000,
            'ops_bps'        => 1000,
            'status'         => 'active',
            'effective_from' => now(),
        ]);

        // Both versions must exist — history is immutable
        $this->assertSame(2, SplitProfileVersion::where('profile_key', 'social_pilot_45_45_10')->count());
    }

    public function test_is_active_helper(): void
    {
        $profile = SplitProfileVersion::activeForKey('library_80_10_10');
        $this->assertTrue($profile->isActive());

        $profile->update(['status' => 'deprecated']);
        $profile->refresh();
        $this->assertFalse($profile->isActive());
    }
}
