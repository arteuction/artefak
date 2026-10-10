<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_policies', function (Blueprint $table): void {
            $table->id();

            // Policy type: terms_of_service, privacy_policy, cookie_policy, seller_agreement
            $table->enum('type', ['terms_of_service', 'privacy_policy', 'cookie_policy', 'seller_agreement']);

            // Semantic version (e.g. "2.1.0")
            $table->string('version', 20);

            // Effective date from which this version is mandatory
            $table->date('effective_from');

            // Full text or URL to external document
            $table->text('content_url')->nullable();
            $table->longText('content_text')->nullable();

            // Whether this version is currently active (only one active per type)
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_policies');
    }
};
