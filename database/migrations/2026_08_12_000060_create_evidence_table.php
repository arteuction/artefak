<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General evidence registry.
 *
 * Covers all 8 evidence types:
 *   authenticity | provenance | condition | payment |
 *   donation     | ownership  | delivery  | impact
 *
 * Polymorphic on subject: the thing being evidenced (an Artwork, Donation,
 * AuctionItem, SellNowOffer, OwnershipTransfer, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table): void {
            $table->id();

            // Subject — what this evidence is about
            $table->morphs('subject');  // adds subject_type + subject_id + index

            // Evidence classification
            $table->string('type', 30);          // one of the 8 types above
            $table->string('subtype', 60)->nullable();  // e.g. 'certificate_of_authenticity'

            // Document / file reference
            $table->string('document_path', 500)->nullable();
            $table->string('document_mime', 100)->nullable();

            // Issuer — free-form; could be a lab, notary, gallery, system
            $table->string('issuer', 200)->nullable();
            $table->date('issued_at')->nullable();

            // Verification
            // 'pending' | 'verified' | 'rejected'
            $table->string('verification_status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable(); // users.id

            // Optional link back to an ImpactProject
            $table->unsignedBigInteger('impact_project_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('verification_status');
            $table->index('impact_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
