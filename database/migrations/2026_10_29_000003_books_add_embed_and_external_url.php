<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // External / embedded sources (open-access, Google Books, etc.)
            // When set, no book_file is required for reading.
            $table->string('external_url', 500)->nullable()->after('preview_pages');
            $table->text('embed_code')->nullable()->after('external_url');

            // Password protection for private/invitation-only titles
            $table->string('access_password', 255)->nullable()->after('embed_code');

            // Denormalised rating cache — recomputed after each approved review
            $table->decimal('avg_rating', 3, 2)->nullable()->after('is_featured');
            $table->unsignedInteger('review_count')->default(0)->after('avg_rating');

            $table->index('avg_rating');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn([
                'external_url',
                'embed_code',
                'access_password',
                'avg_rating',
                'review_count',
            ]);
        });
    }
};
