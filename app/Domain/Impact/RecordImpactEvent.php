<?php

declare(strict_types=1);

namespace App\Domain\Impact;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\ArtworkSdgClaim;
use App\Models\ImpactEvent;
use InvalidArgumentException;

final class RecordImpactEvent
{
    /**
     * @param  array{art_lot_id?: int, donation_id?: int, auction_item_id?: int, sell_now_offer_id?: int}  $source
     */
    public function execute(
        ArtworkSdgClaim $claim,
        ImpactMetric    $metric,
        int             $magnitude,
        string          $idempotencyKey,
        array           $source = [],
        ?string         $note   = null,
    ): ImpactEvent {
        if ($claim->status !== 'approved') {
            throw new InvalidArgumentException(
                "SDG claim must be approved before recording impact (status: {$claim->status})."
            );
        }

        if ($magnitude <= 0) {
            throw new InvalidArgumentException('Impact magnitude must be positive.');
        }

        $this->validateSource($source);

        $event = ImpactEvent::create([
            'artwork_sdg_claim_id'    => $claim->id,
            'sdg_number'              => $claim->sdg_number,
            'metric'                  => $metric->value,
            'magnitude'               => $magnitude,
            'currency'                => 'EUR',
            'type'                    => 'event',
            'art_lot_id'              => $source['art_lot_id'] ?? null,
            'donation_id'             => $source['donation_id'] ?? null,
            'auction_item_id'         => $source['auction_item_id'] ?? null,
            'sell_now_offer_id'       => $source['sell_now_offer_id'] ?? null,
            'idempotency_key'         => $idempotencyKey,
            'note'                    => $note,
        ]);

        (new AppendDomainEvent())->execute(
            aggregate: $event,
            eventType: 'impact.recorded',
            payload: [
                'sdg_number'  => $claim->sdg_number,
                'metric'      => $metric->value,
                'magnitude'   => $magnitude,
                'claim_id'    => $claim->id,
                'is_monetary' => $metric->isMonetary(),
            ],
            idempotencyKey: "impact.recorded:{$idempotencyKey}",
        );

        return $event;
    }

    private function validateSource(array $source): void
    {
        $set = array_filter([
            $source['art_lot_id']       ?? null,
            $source['donation_id']      ?? null,
            $source['auction_item_id']  ?? null,
            $source['sell_now_offer_id'] ?? null,
        ]);

        if (count($set) > 1) {
            throw new InvalidArgumentException(
                'Impact source must be at most one of: art_lot_id, donation_id, auction_item_id, sell_now_offer_id.'
            );
        }
    }
}
