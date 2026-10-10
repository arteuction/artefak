<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 100)->unique();

            // Institution type: museum, gallery, university, foundation, other
            $table->enum('type', ['museum', 'gallery', 'university', 'foundation', 'other'])
                ->default('other');

            // Country code (ISO 3166-1 alpha-2)
            $table->string('country_code', 2)->nullable();

            // Website and API endpoint for harvesting or loan management
            $table->string('website_url', 512)->nullable();
            $table->string('api_endpoint', 512)->nullable();

            // Europeana Data Provider identifier
            $table->string('europeana_provider_id', 255)->nullable()->unique();

            // Contact details
            $table->string('contact_email', 255)->nullable();

            // Whether this institution is active/partnered
            $table->boolean('is_active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });

        // Artwork ↔ Institution loans/deposits link table
        Schema::create('institution_artworks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();
            $table->foreignId('artwork_id')
                ->constrained('artworks')
                ->cascadeOnDelete();

            // Relationship type: loan, deposit, permanent_transfer, exhibition
            $table->enum('relationship', ['loan', 'deposit', 'permanent_transfer', 'exhibition'])
                ->default('loan');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->unique(['institution_id', 'artwork_id', 'relationship', 'start_date'], 'inst_aw_rel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_artworks');
        Schema::dropIfExists('institutions');
    }
};
