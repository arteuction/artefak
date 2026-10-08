<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic transactional outbox for all domain events.
        // Written atomically inside the domain action's DB transaction.
        // A worker reads pending rows and dispatches to subscribers
        // (webhooks, ArtMetro sync, audit trail, analytics).
        // Separate from transfer_outbox which is Stripe-specific.
        Schema::create('domain_events', function (Blueprint $table) {
            $table->id();

            // The aggregate root that raised the event
            $table->string('aggregate_type', 100); // e.g. 'ArtLot', 'Consignment'
            $table->unsignedBigInteger('aggregate_id');

            // Fully-qualified event name, e.g. 'art_lot.sold', 'consignment.activated'
            $table->string('event_type', 100);

            // Frozen snapshot of event data at time of recording
            $table->json('payload');

            // Caller-supplied idempotency key — prevents duplicate events on retry
            $table->string('idempotency_key', 100)->unique();

            $table->enum('status', ['pending', 'processing', 'dispatched', 'failed'])
                  ->default('pending');

            // Retry machinery
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id'], 'de_aggregate_idx');
            $table->index(['status', 'next_attempt_at'],      'de_dispatch_queue_idx');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
