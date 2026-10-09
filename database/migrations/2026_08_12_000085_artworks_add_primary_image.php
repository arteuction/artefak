<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table): void {
            // S3 object key for the primary display image, e.g. 'artworks/123/primary.jpg'
            $table->string('primary_image_key', 500)->nullable()->after('ar_model_url');

            // Upload lifecycle: null = no image, 'pending' = presigned URL issued but not yet confirmed,
            // 'confirmed' = object verified in S3, 'processing' = CDN/thumbnail pipeline running
            $table->enum('primary_image_status', ['pending', 'confirmed', 'processing'])
                  ->nullable()
                  ->after('primary_image_key');
        });
    }

    public function down(): void
    {
        Schema::table('artworks', function (Blueprint $table): void {
            $table->dropColumn(['primary_image_key', 'primary_image_status']);
        });
    }
};
