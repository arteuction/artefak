<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Impact\ImpactMetric;
use App\Domain\Impact\RecordImpactEvent;
use App\Http\Controllers\Controller;
use App\Models\ArtworkSdgClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * POST /api/v1/sdg-claims/{claim}/impact-events
 *
 * Admin/operator: record an impact event against an approved SDG claim.
 */
final class ImpactRecordController extends Controller
{
    public function store(Request $request, ArtworkSdgClaim $claim): JsonResponse
    {
        $metricValues = array_column(ImpactMetric::cases(), 'value');

        $data = $request->validate([
            'metric'              => ['required', Rule::in($metricValues)],
            'magnitude'           => ['required', 'integer', 'min:1'],
            'idempotency_key'     => ['required', 'string', 'max:200'],
            'note'                => ['nullable', 'string', 'max:2000'],
            'art_lot_id'          => ['nullable', 'integer', 'exists:art_lots,id'],
            'donation_id'         => ['nullable', 'integer', 'exists:donations,id'],
            'auction_item_id'     => ['nullable', 'integer', 'exists:auction_items,id'],
            'sell_now_offer_id'   => ['nullable', 'integer', 'exists:sell_now_offers,id'],
        ]);

        try {
            $event = (new RecordImpactEvent())->execute(
                claim:          $claim,
                metric:         ImpactMetric::from($data['metric']),
                magnitude:      (int) $data['magnitude'],
                idempotencyKey: $data['idempotency_key'],
                source:         array_filter([
                    'art_lot_id'        => $data['art_lot_id'] ?? null,
                    'donation_id'       => $data['donation_id'] ?? null,
                    'auction_item_id'   => $data['auction_item_id'] ?? null,
                    'sell_now_offer_id' => $data['sell_now_offer_id'] ?? null,
                ]),
                note: $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($event, 201);
    }
}
