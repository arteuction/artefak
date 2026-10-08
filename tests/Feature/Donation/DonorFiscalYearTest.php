<?php

declare(strict_types=1);

namespace Tests\Feature\Donation;

use App\Domain\Donation\AccumulateDonorFiscalYear;
use App\Domain\Donation\RecordDonation;
use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\DonorFiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 13-F — DonorFiscalYear aggregate tests.
 */
final class DonorFiscalYearTest extends TestCase
{
    use RefreshDatabase;

    private User              $donor;
    private DonationRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->create();

        $this->recipient = DonationRecipient::create([
            'name'              => 'Test NGO',
            'eik'               => 'BG123456789',
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_1',
            'deduction_bps'     => 1000,
            'status'            => 'active',
        ]);
    }

    // -----------------------------------------------------------------------
    // Row creation
    // -----------------------------------------------------------------------

    public function test_first_donation_creates_fiscal_year_row(): void
    {
        $donation = $this->makeDonation(10000, 'dk-fy-1');

        (new AccumulateDonorFiscalYear())->execute($donation);

        $this->assertDatabaseCount('donor_fiscal_years', 1);
        $this->assertDatabaseHas('donor_fiscal_years', [
            'donor_id'                => $this->donor->id,
            'fiscal_year'             => (int) now()->format('Y'),
            'eligibility_basis'       => 'ZKPO_ART31_1',
            'aggregate_donated_cents' => 10000,
            'donation_count'          => 1,
        ]);
    }

    public function test_second_donation_same_basis_accumulates(): void
    {
        $d1 = $this->makeDonation(10000, 'dk-fy-2a');
        $d2 = $this->makeDonation(5000,  'dk-fy-2b');

        $action = new AccumulateDonorFiscalYear();
        $action->execute($d1);
        $action->execute($d2);

        $this->assertDatabaseCount('donor_fiscal_years', 1);
        $row = DonorFiscalYear::first();
        $this->assertSame(15000, $row->aggregate_donated_cents);
        $this->assertSame(2, $row->donation_count);
    }

    public function test_max_deductible_accumulates_with_donations(): void
    {
        // ZKPO_ART31_1 = 10% → 10000 donated → 1000 deductible
        $donation = $this->makeDonation(10000, 'dk-fy-3');

        (new AccumulateDonorFiscalYear())->execute($donation);

        $row = DonorFiscalYear::first();
        $this->assertSame(1000, $row->aggregate_max_deductible_cents);
    }

    public function test_different_donors_have_separate_rows(): void
    {
        $donor2 = User::factory()->create();

        $d1 = $this->makeDonation(10000, 'dk-fy-4a');
        $d2 = Donation::create([
            'donation_recipient_id' => $this->recipient->id,
            'donor_id'              => $donor2->id,
            'donated_cents'         => 10000,
            'currency'              => 'EUR',
            'eligibility_basis'     => 'ZKPO_ART31_1',
            'deduction_bps'         => 1000,
            'max_deductible_cents'  => 1000,
            'type'                  => 'donation',
            'status'                => 'confirmed',
            'idempotency_key'       => 'dk-fy-4b',
        ]);

        $action = new AccumulateDonorFiscalYear();
        $action->execute($d1);
        $action->execute($d2);

        $this->assertDatabaseCount('donor_fiscal_years', 2);
    }

    public function test_documentation_status_defaults_to_incomplete(): void
    {
        $donation = $this->makeDonation(10000, 'dk-fy-5');
        (new AccumulateDonorFiscalYear())->execute($donation);

        $row = DonorFiscalYear::first();
        $this->assertSame('incomplete', $row->documentation_status);
        $this->assertFalse($row->isDocumentationReady());
        $this->assertFalse($row->isSubmitted());
    }

    public function test_documentation_status_can_be_updated_to_ready(): void
    {
        $donation = $this->makeDonation(10000, 'dk-fy-6');
        $row = (new AccumulateDonorFiscalYear())->execute($donation);

        $row->update(['documentation_status' => 'ready']);
        $row->refresh();

        $this->assertTrue($row->isDocumentationReady());
    }

    public function test_returns_updated_row_after_second_accumulation(): void
    {
        $d1 = $this->makeDonation(10000, 'dk-fy-7a');
        $d2 = $this->makeDonation(5000,  'dk-fy-7b');

        $action = new AccumulateDonorFiscalYear();
        $action->execute($d1);
        $row = $action->execute($d2);

        $this->assertSame(15000, $row->aggregate_donated_cents);
    }

    // -----------------------------------------------------------------------
    // ZKPO basis point calculation correctness
    // -----------------------------------------------------------------------

    public function test_patronage_basis_yields_15_percent(): void
    {
        $recipient = DonationRecipient::create([
            'name'              => 'Patronage Org',
            'eik'               => 'BG987654321',
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_3_PATRONAGE',
            'deduction_bps'     => 1500,
            'status'            => 'active',
        ]);

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   10000,
            idempotencyKey: 'dk-patronage-1',
        );

        // 15% of 10000 = 1500
        $this->assertSame(1500, $donation->max_deductible_cents);

        (new AccumulateDonorFiscalYear())->execute($donation);

        $row = DonorFiscalYear::where('eligibility_basis', 'ZKPO_ART31_3_PATRONAGE')->first();
        $this->assertSame(1500, $row->aggregate_max_deductible_cents);
    }

    public function test_nhi_child_treatment_yields_50_percent(): void
    {
        $recipient = DonationRecipient::create([
            'name'              => 'NHI Org',
            'eik'               => 'BG111111111',
            'legal_type'        => 'ngo',
            'eligibility_basis' => 'ZKPO_ART31_2_NHI_CHILD_TREATMENT',
            'deduction_bps'     => 5000,
            'status'            => 'active',
        ]);

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   10000,
            idempotencyKey: 'dk-nhi-1',
        );

        $this->assertSame(5000, $donation->max_deductible_cents);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeDonation(int $donatedCents, string $key): Donation
    {
        return (new RecordDonation())->execute(
            recipient:      $this->recipient,
            donor:          $this->donor,
            donatedCents:   $donatedCents,
            idempotencyKey: $key,
        );
    }
}
