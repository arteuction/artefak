<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();

            // Nullable — free books and promotional grants have no purchase
            $table->foreignId('book_purchase_id')
                  ->nullable()
                  ->constrained('book_purchases')
                  ->nullOnDelete();

            // Source distinguishes financial purchases from free/promo/admin grants
            $table->enum('source', ['paid', 'free', 'promotional', 'admin'])->default('paid');

            // File version access (null = always latest)
            $table->unsignedSmallInteger('file_version')->nullable();

            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            // Active = granted_at IS NOT NULL AND revoked_at IS NULL
            $table->index(['user_id', 'book_id', 'revoked_at']);
            $table->index(['book_id', 'source']);

            // One active entitlement per user per book — enforced in domain via unique on active rows
            $table->unique(['user_id', 'book_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_entitlements');
    }
};
