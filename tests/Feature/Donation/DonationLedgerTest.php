<?php

declare(strict_types=1);

namespace Tests\Feature\Donation;

use App\Domain\Donation\EligibilityBasis;
use App\Domain\Donation\RecordDonation;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Donation;
use App\Models\DonationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DonationLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User   $donor;
    private ArtLot $artLot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->donor = User::factory()->create(['role' => 'buyer']);

        $artist  = User::factory()->create(['role' => 'artist']);
        $artwork = Artwork::create([
            'user_id' => $artist->id,
            'title'   => 'Donated Work',
            'slug'    => 'donated-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->artLot = ArtLot::create([
            'artwork_id'   => $artwork->id,
            'consignor_id' => $artist->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'active',
            'currency'     => 'EUR',
        ]);
    }

    private function makeRecipient(string $basis, string $eik = '111222333'): DonationRecipient
    {
        $bps = EligibilityBasis::from($basis)->deductionBps();

        return DonationRecipient::create([
            'name'              => 'Test Recipient',
            'eik'               => $eik,
            'legal_type'        => 'ngo',
            'eligibility_basis' => $basis,
            'deduction_bps'     => $bps,
            'status'            => 'active',
        ]);
    }

    // ── DonationRecipient model ────────────────────────────────────────────────

    public function test_recipient_stores_all_fields(): void
    {
        $r = $this->makeRecipient('ZKPO_ART31_1');

        $this->assertDatabaseHas('donation_recipients', ['eik' => '111222333']);
        $this->assertEquals(1000, $r->deduction_bps);
        $this->assertTrue($r->isActive());
        $this->assertEquals(EligibilityBasis::ZKPO_ART31_1, $r->eligibilityBasis());
    }

    public function test_all_four_eligibility_bases_can_be_stored(): void
    {
        $bases = EligibilityBasis::cases();
        foreach ($bases as $i => $basis) {
            $r = $this->makeRecipient($basis->value, str_pad((string)($i + 1), 9, '0'));
            $this->assertEquals($basis->deductionBps(), $r->deduction_bps);
        }
    }

    // ── RecordDonation ─────────────────────────────────────────────────────────

    public function test_record_donation_creates_pending_row(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1');

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   10000,
            idempotencyKey: 'idem-test-001',
            source:         ['art_lot_id' => $this->artLot->id],
        );

        $this->assertInstanceOf(Donation::class, $donation);
        $this->assertEquals('pending', $donation->status);
        $this->assertEquals('donation', $donation->type);
        $this->assertEquals(10000, $donation->donated_cents);
        $this->assertEquals(1000,  $donation->deduction_bps);
        // Corrected: max_deductible_cents = donatedCents (provisional upper bound).
        // The profit-based ceiling is applied at annual assessment, not at donation time.
        $this->assertEquals(10000, $donation->max_deductible_cents);
        $this->assertEquals('ZKPO_ART31_1', $donation->eligibility_basis);
    }

    public function test_patronage_records_15_percent(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_3_PATRONAGE', '222333444');

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   20000,
            idempotencyKey: 'idem-patronage-001',
        );

        $this->assertEquals(1500, $donation->deduction_bps);
        // Corrected: provisional max = full donation; annual assessment applies 15%-of-profit ceiling
        $this->assertEquals(20000, $donation->max_deductible_cents);
    }

    public function test_nhi_child_treatment_records_50_percent(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_2_NHI_CHILD_TREATMENT', '333444555');

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   10000,
            idempotencyKey: 'idem-nhi-001',
        );

        $this->assertEquals(5000, $donation->deduction_bps);
        // Corrected: provisional max = full donation (10000), not donated * 50%
        $this->assertEquals(10000, $donation->max_deductible_cents);
    }

    public function test_donation_source_is_optional(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '444555666');

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   5000,
            idempotencyKey: 'idem-no-source',
        );

        $this->assertNull($donation->art_lot_id);
        $this->assertNull($donation->auction_item_id);
        $this->assertNull($donation->sell_now_offer_id);
    }

    public function test_multiple_sources_throws(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '555666777');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exactly one/');

        (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   5000,
            idempotencyKey: 'idem-multi-source',
            source:         ['art_lot_id' => $this->artLot->id, 'auction_item_id' => 1],
        );
    }

    public function test_inactive_recipient_throws(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '666777888');
        $recipient->update(['status' => 'inactive']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not active');

        (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   5000,
            idempotencyKey: 'idem-inactive',
        );
    }

    public function test_idempotency_key_is_unique(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '777888999');

        (new RecordDonation())->execute(
            recipient: $recipient, donor: $this->donor,
            donatedCents: 1000, idempotencyKey: 'same-key',
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        (new RecordDonation())->execute(
            recipient: $recipient, donor: $this->donor,
            donatedCents: 2000, idempotencyKey: 'same-key',
        );
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function test_donation_belongs_to_recipient_and_donor(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '888999000');

        $donation = (new RecordDonation())->execute(
            recipient:      $recipient,
            donor:          $this->donor,
            donatedCents:   5000,
            idempotencyKey: 'idem-rel-001',
        );

        $this->assertEquals($recipient->id, $donation->recipient->id);
        $this->assertEquals($this->donor->id, $donation->donor->id);
    }

    public function test_recipient_has_many_donations(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '999000111');

        (new RecordDonation())->execute(recipient: $recipient, donor: $this->donor,
            donatedCents: 1000, idempotencyKey: 'k1');
        (new RecordDonation())->execute(recipient: $recipient, donor: $this->donor,
            donatedCents: 2000, idempotencyKey: 'k2');

        $this->assertCount(2, $recipient->donations);
    }

    public function test_is_pending_and_is_confirmed(): void
    {
        $recipient = $this->makeRecipient('ZKPO_ART31_1', '000111222');
        $donation  = (new RecordDonation())->execute(recipient: $recipient, donor: $this->donor,
            donatedCents: 5000, idempotencyKey: 'k-status');

        $this->assertTrue($donation->isPending());
        $this->assertFalse($donation->isConfirmed());

        $donation->update(['status' => 'confirmed']);
        $this->assertFalse($donation->fresh()->isPending());
        $this->assertTrue($donation->fresh()->isConfirmed());
    }
}
