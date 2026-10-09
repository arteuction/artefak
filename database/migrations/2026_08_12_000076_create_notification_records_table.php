<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_records', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('channel', 20)->default('mail');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_records');
    }
};
