<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Artwork identity is stable; descriptive metadata is versioned.
        // Each revision is a full snapshot — no diffing required.
        // One revision is 'active' at a time; older ones become 'superseded'.
        // ArtLot records which revision was in effect at time of sale.
        Schema::create('artwork_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')
                  ->constrained('artworks')
                  ->cascadeOnDelete();

            // Auto-incrementing per artwork, set by CreateArtworkRevision
            $table->unsignedSmallInteger('version');

            // Descriptive fields — a snapshot, not a diff
            $table->string('title');
            $table->unsignedSmallInteger('year_created')->nullable();
            $table->string('medium', 200)->nullable();          // oil on canvas, bronze, etc.
            $table->string('dimensions_notes', 200)->nullable(); // e.g. "45 × 60 cm"
            $table->text('description')->nullable();
            $table->string('edition_info', 100)->nullable();    // e.g. "3/10", "AP", "unique"

            // Who made the revision and why
            $table->foreignId('revised_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            $table->enum('reason', [
                'initial',                // first record at artwork creation
                'correction',             // factual error corrected
                'restoration_documented', // post-restoration update
                'attribution_updated',    // artist/school attribution changed
                'provenance_expanded',    // new ownership history discovered
                'certificate_added',      // certificate of authenticity obtained
            ])->default('initial');

            $table->enum('status', ['draft', 'active', 'superseded'])->default('draft');
            $table->timestamp('effective_from')->nullable(); // set when activated

            $table->timestamps();

            $table->unique(['artwork_id', 'version']);
            $table->index(['artwork_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_revisions');
    }
};
