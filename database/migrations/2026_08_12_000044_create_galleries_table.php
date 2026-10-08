<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['private', 'public', 'institutional', 'online'])->default('private');

            // Optional physical presence
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();

            // Contact & legal identity
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('eik', 20)->nullable();          // Bulgarian company registration
            $table->string('stripe_account_id', 100)->nullable();

            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();

            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galleries');
    }
};
