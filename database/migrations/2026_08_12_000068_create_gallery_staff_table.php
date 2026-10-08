<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization accounts for galleries.
 *
 * A gallery is an organization; individual users hold staff roles within it.
 * A user can hold more than one role (e.g., owner + curator).
 * Multiple users can hold the same role (e.g., two curators).
 *
 * Roles:
 *   owner       — full control, can add/remove staff, change billing
 *   finance     — can view settlements, trigger payouts, view consignment fees
 *   curator     — can manage artworks, consignments, exhibitions
 *   sales       — can create/edit ArtLots, sell-now offers, respond to buyers
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gallery_id')->constrained('galleries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'owner' | 'finance' | 'curator' | 'sales'
            $table->string('role', 30);

            // Invitation lifecycle: invited → active | revoked
            $table->string('status', 20)->default('active');

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One user can hold each role once per gallery
            $table->unique(['gallery_id', 'user_id', 'role'], 'gallery_staff_role_unique');
            $table->index(['gallery_id', 'status']);
            $table->index(['user_id',    'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_staff');
    }
};
