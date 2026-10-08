<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence attached to a dispute (photos, documents, messages, timestamps).
 * Separate from the general evidence registry — disputes have their own
 * evidentiary chain that must not be mixed with provenance/authenticity records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dispute_id');
            $table->unsignedBigInteger('submitted_by');  // users.id

            // 'document' | 'photo' | 'message_log' | 'condition_report' | 'other'
            $table->string('type', 30);
            $table->string('document_path', 500)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('dispute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidence');
    }
};
