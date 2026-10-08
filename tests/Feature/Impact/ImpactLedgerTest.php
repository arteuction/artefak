<?php

declare(strict_types=1);

namespace Tests\Feature\Impact;

use App\Domain\Impact\ImpactMetric;
use App\Domain\Impact\RecordImpactEvent;
use App\Domain\Impact\SdgGoal;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\ArtworkSdgClaim;
use App\Models\ImpactEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ImpactLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User           $artist;
    private Artwork        $artwork;
    private ArtworkSdgClaim $claim;
    private ArtLot         $artLot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist  = User::factory()->create(['role' => 'artist']);

        $this->artwork = Artwork::create([
            'user_id' => $this->artist->id,
            'title'   => 'Impact Artwork',
            'slug'    => 'impact-' . uniqid(),
            'status'  => 'listed',
        ]);

        $this->claim = ArtworkSdgClaim::create([
            'artwork_id' => $this->artwork->id,
            'sdg_number' => 4,
            'rationale'  => 'Promotes quality education through art',
            'status'     => 'approved',
        ]);

        $this->artLot = ArtLot::create([
            'artwork_id'   => $this->artwork->id,
            'consignor_id' => $this->artist->id,
            'sale_mode'    => 'sell_now',
            'status'       => 'sold',
            'currency'     => 'EUR',
        ]);
    }

    // ── SdgGoal / ImpactMetric enums ───────────────────────────────────────────

    public function test_sdg_goal_from_value(): void
    {
        $this->assertEquals(SdgGoal::QualityEducation, SdgGoal::from(4));
        $this->assertEquals('SDG 4 — Quality Education', SdgGoal::from(4)->label());
    }

    public function test_impact_metric_from_value(): void
    {
        $this->assertEquals(ImpactMetric::SaleAmountEur, ImpactMetric::from('sale_amount_eur'));
        $this->assertTrue(ImpactMetric::SaleAmountEur->isMonetary());
    }

    // ── RecordImpactEvent ──────────────────────────────────────────────────────

    public function test_record_event_creates_row(): void
    {
        $event = (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::SaleAmountEur,
            magnitude:      50000,
            idempotencyKey: 'impact-001',
            source:         ['art_lot_id' => $this->artLot->id],
        );

        $this->assertInstanceOf(ImpactEvent::class, $event);
        $this->assertEquals(4,              $event->sdg_number);
        $this->assertEquals('sale_amount_eur', $event->metric);
        $this->assertEquals(50000,          $event->magnitude);
        $this->assertEquals('event',        $event->type);
        $this->assertFalse($event->isReversal());
    }

    public function test_pending_claim_throws(): void
    {
        $claim = ArtworkSdgClaim::create([
            'artwork_id' => $this->artwork->id,
            'sdg_number' => 5,
            'rationale'  => 'pending claim',
            'status'     => 'pending',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be approved');

        (new RecordImpactEvent())->execute(
            claim:          $claim,
            metric:         ImpactMetric::ArtworksSold,
            magnitude:      1,
            idempotencyKey: 'impact-pending',
        );
    }

    public function test_rejected_claim_throws(): void
    {
        $claim = ArtworkSdgClaim::create([
            'artwork_id' => $this->artwork->id,
            'sdg_number' => 6,
            'rationale'  => 'rejected',
            'status'     => 'rejected',
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new RecordImpactEvent())->execute(
            claim:          $claim,
            metric:         ImpactMetric::ArtworksSold,
            magnitude:      1,
            idempotencyKey: 'impact-rejected',
        );
    }

    public function test_zero_magnitude_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::AudienceReach,
            magnitude:      0,
            idempotencyKey: 'impact-zero',
        );
    }

    public function test_multiple_sources_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most one/');

        (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::SaleAmountEur,
            magnitude:      10000,
            idempotencyKey: 'impact-multi',
            source:         ['art_lot_id' => $this->artLot->id, 'auction_item_id' => 1],
        );
    }

    public function test_no_source_is_valid(): void
    {
        $event = (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::AudienceReach,
            magnitude:      250,
            idempotencyKey: 'impact-no-source',
        );

        $this->assertNull($event->art_lot_id);
        $this->assertNull($event->donation_id);
        $this->assertNull($event->auction_item_id);
    }

    public function test_idempotency_key_is_unique(): void
    {
        (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::ArtworksSold,
            magnitude:      1,
            idempotencyKey: 'same-impact-key',
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::ArtworksSold,
            magnitude:      2,
            idempotencyKey: 'same-impact-key',
        );
    }

    public function test_all_seven_metrics_can_be_recorded(): void
    {
        foreach (ImpactMetric::cases() as $i => $metric) {
            $event = (new RecordImpactEvent())->execute(
                claim:          $this->claim,
                metric:         $metric,
                magnitude:      100,
                idempotencyKey: "all-metrics-{$i}",
            );
            $this->assertEquals($metric->value, $event->metric);
        }
    }

    // ── Model helpers ──────────────────────────────────────────────────────────

    public function test_sdg_goal_and_metric_accessors(): void
    {
        $event = (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::DonationAmountEur,
            magnitude:      8000,
            idempotencyKey: 'accessors-test',
        );

        $this->assertEquals(SdgGoal::QualityEducation, $event->sdgGoal());
        $this->assertEquals(ImpactMetric::DonationAmountEur, $event->impactMetric());
        $this->assertTrue($event->impactMetric()->isMonetary());
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function test_event_belongs_to_sdg_claim(): void
    {
        $event = (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::ArtworksSold,
            magnitude:      1,
            idempotencyKey: 'rel-claim',
        );

        $this->assertEquals($this->claim->id, $event->sdgClaim->id);
    }

    public function test_event_belongs_to_art_lot(): void
    {
        $event = (new RecordImpactEvent())->execute(
            claim:          $this->claim,
            metric:         ImpactMetric::SaleAmountEur,
            magnitude:      30000,
            idempotencyKey: 'rel-art-lot',
            source:         ['art_lot_id' => $this->artLot->id],
        );

        $this->assertEquals($this->artLot->id, $event->artLot->id);
    }

    public function test_sdg_number_denormalised_from_claim(): void
    {
        $claim = ArtworkSdgClaim::create([
            'artwork_id' => $this->artwork->id,
            'sdg_number' => 11,
            'rationale'  => 'Sustainable cities',
            'status'     => 'approved',
        ]);

        $event = (new RecordImpactEvent())->execute(
            claim:          $claim,
            metric:         ImpactMetric::AudienceReach,
            magnitude:      500,
            idempotencyKey: 'sdg-11',
        );

        $this->assertEquals(11, $event->sdg_number);
        $this->assertEquals(SdgGoal::SustainableCities, $event->sdgGoal());
    }
}
