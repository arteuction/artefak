<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_recipients', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('eik', 20)->unique();

            // Legal classification of the organization
            $table->enum('legal_type', [
                'ngo',          // Non-governmental organization (ЮЛНЦ)
                'nhi',          // National Health Insurance (НЗОК)
                'municipality', // Municipality (Община)
                'state',        // State / ministry
                'religious',    // Registered religious denomination
                'other',
            ]);

            // ZKPO (ЗКПО) article that governs the deduction for donations to this recipient
            $table->enum('eligibility_basis', [
                'ZKPO_ART31_1',                     // General NGO/municipality/state → 10% of taxable profit
                'ZKPO_ART31_3_PATRONAGE',           // Culture-sector patronage → 15% of taxable profit
                'ZKPO_ART31_2_NHI_CHILD_TREATMENT', // NHI child treatment → 50%
                'ZKPO_ART31_2_ASSISTED_REPRODUCTION', // NHI assisted reproduction → 50%
            ]);

            // Computed deduction rate (stored for audit; must match eligibility_basis)
            $table->unsignedSmallInteger('deduction_bps'); // basis points e.g. 1000 = 10%

            $table->string('stripe_account_id', 100)->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamps();

            $table->index(['status', 'eligibility_basis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_recipients');
    }
};
