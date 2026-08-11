<?php

declare(strict_types=1);

namespace App\Domain\Auction;

use App\Domain\Settlement\CreateSettlement;
use App\Domain\Settlement\Money;
use App\Domain\Settlement\RecipientLine;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Auction;
use App\Models\AuctionItem;
use Stripe\StripeClient;

/**
 * Captures all winning PaymentIntents and creates settlements for an auction.
 *
 * Called once after all lots are closed (auction status → 'closed').
 * Idempotent: CreateSettlement deduplicates on stripe_payment_intent_id.
 *
 * For each sold lot:
 *   1. Capture the winning bid's Stripe PaymentIntent.
 *   2. Call SettlementCalculator to split the gross amount.
 *   3. Build RecipientLines (artist + artefak fund + ops).
 *   4. Persist via CreateSettlement (idempotent, with outbox).
 */
final class SettleAuction
{
    public function __construct(
        private readonly StripeClient         $stripe,
        private readonly SettlementCalculator $calculator,
        private readonly CreateSettlement     $createSettlement,
    ) {}

    /**
     * @param  string $stripeEventId  The Stripe event that triggered settlement
     * @return int[]  List of settlement IDs created (or already existing)
     */
    public function execute(Auction $auction, string $stripeEventId): array
    {
        $settlementIds = [];

        $soldItems = AuctionItem::with(['winningBid', 'artwork.artist'])
            ->where('auction_id', $auction->id)
            ->where('status', 'sold')
            ->whereNotNull('winning_bid_id')
            ->get();

        foreach ($soldItems as $item) {
            $bid = $item->winningBid;

            if ($bid === null || $bid->stripe_payment_intent_id === null) {
                continue;
            }

            // 1. Capture the authorized PaymentIntent
            try {
                $this->stripe->paymentIntents->capture($bid->stripe_payment_intent_id);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Already captured (idempotent) or failed — log and skip
                logger()->warning('SettleAuction: capture skipped', [
                    'pi'     => $bid->stripe_payment_intent_id,
                    'reason' => $e->getMessage(),
                ]);
                continue;
            }

            // 2. Calculate split
            $gross  = Money::fromCents($bid->amount_cents, $auction->currency ?? 'EUR');
            $result = $this->calculator->calculate($gross);

            // 3. Build recipient lines
            $artist   = $item->artwork?->artist;
            $currency = $auction->currency ?? 'EUR';

            $recipients = [
                new RecipientLine(
                    type:            'artist',
                    amount:          $result->artist,
                    legalEntityId:   $artist?->id,
                    entityName:      $artist?->name ?? 'Unknown Artist',
                    entityRole:      'artist',
                    stripeAccountId: null, // populated when artist completes Stripe onboarding
                    weight:          $result->artistBps,
                ),
                new RecipientLine(
                    type:            'fund',
                    amount:          $result->fund,
                    entityName:      'ArtMetro Social Fund',
                    entityRole:      'fund',
                    weight:          $result->fundBps,
                ),
                new RecipientLine(
                    type:            'ops',
                    amount:          $result->ops,
                    entityName:      'ArteUction Operations',
                    entityRole:      'ops',
                    weight:          $result->opsBps,
                ),
            ];

            // 4. Persist settlement (idempotent)
            $settlementIds[] = $this->createSettlement->execute(
                result:        $result,
                paymentIntent: $bid->stripe_payment_intent_id,
                stripeEvent:   $stripeEventId,
                recipients:    $recipients,
                auctionId:     $auction->id,
            );
        }

        return $settlementIds;
    }
}
