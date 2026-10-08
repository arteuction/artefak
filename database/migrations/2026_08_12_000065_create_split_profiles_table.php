<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists SplitProfile versions to the database.
 *
 * The domain `SplitProfile` value object remains the source of truth for
 * calculation; this table is the audit and versioning layer.
 *
 * Rules:
 *  - A profile_key can have multiple versions (monotonically increasing).
 *  - Only one version per key may be 'active' at any time.
 *  - Once a version is 'superseded', it MUST NOT be modified — settlements
 *    already frozen against it are correct by definition.
 *  - 'deprecated' means: still valid for historical settlements, not
 *    available for new ArtLots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('profile_key', 60)->index();
            $table->unsignedSmallInteger('version');

            // Basis points — must sum to 10000
            $table->unsignedSmallInteger('artist_bps');
            $table->unsignedSmallInteger('fund_bps');
            $table->unsignedSmallInteger('ops_bps');

            $table->text('description')->nullable();

            // 'active' | 'superseded' | 'deprecated'
            $table->string('status', 20)->default('active');

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();  // users.id

            $table->timestamps();

            $table->unique(['profile_key', 'version'], 'split_profiles_key_version_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_profiles');
    }
};
