<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency inbox for domain-event consumers (e.g. ArtMetro).
        // A consumer writes a row here before applying side-effects.
        // If the row already exists, the side-effect is skipped.
        // This makes event delivery at-least-once safe: the same event can
        // arrive three times but be applied exactly once per consumer.
        Schema::create('consumer_inbox', function (Blueprint $table) {
            $table->id();

            // Logical name of the consuming system, e.g. 'artmetro', 'analytics'
            $table->string('consumer', 60);

            // The domain_events.id that was delivered
            $table->unsignedBigInteger('domain_event_id');

            // Denormalised for fast queries without joining domain_events
            $table->string('event_type', 100);

            $table->timestamp('processed_at');
            $table->text('result')->nullable(); // optional short note from the consumer

            $table->timestamps();

            // One consumer processes each event at most once
            $table->unique(['consumer', 'domain_event_id'], 'inbox_consumer_event_unique');
            $table->index('consumer');
            $table->index('domain_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_inbox');
    }
};
