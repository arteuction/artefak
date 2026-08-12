<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();

            // Who acted
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();

            // What was affected — polymorphic (ArtistProfile, ArtistApplication, Artwork, ArtworkSdgClaim…)
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // What happened
            $table->string('action', 80); // e.g. 'artist.approved', 'sdg_claim.rejected'
            $table->json('payload')->nullable(); // before/after diff or free context

            // Immutable timestamp — no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
    }
};
