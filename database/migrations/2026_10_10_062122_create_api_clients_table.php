<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();

            // Human-readable name for the client application
            $table->string('name', 255);

            // Hashed API key (never store plaintext)
            $table->string('key_hash', 64)->unique();

            // Key prefix for display (e.g. "artk_live_XXXX") — 12 chars
            $table->string('key_prefix', 12);

            // Owning user or institution (nullable = system client)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('institution_id')
                ->nullable()
                ->constrained('institutions')
                ->nullOnDelete();

            // Allowed scopes as a JSON array (e.g. ["artworks:read","lots:read"])
            $table->json('scopes')->default('[]');

            // Rate limit overrides (null = use default)
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
