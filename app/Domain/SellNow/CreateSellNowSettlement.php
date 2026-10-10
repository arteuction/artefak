<?php

declare(strict_types=1);

namespace App\Domain\SellNow;

use App\Domain\Settlement\Money;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\Settlement\SplitProfile;
use App\Models\SellNowOffer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a settlement + ledger_entries row for a paid sell-now offer.
 *
 * Idempotent: if a settlement with this payment_intent_id already exists,
 * returns the existing settlement ID.
 *
 * Ledger invariant: every paid offer produces exactly one settlement row
 * and at least one ledger_entry credit row — never more, never fewer.
 *
 * Uses the 'social_pilot_45_45_10' split profile (artist 45 / fund 45 / ops 10).
 */
final class CreateSellNowSettlement
{
    public function __construct(
        private readonly SettlementCalculator $calculator,
    ) {}

    public function execute(SellNowOffer $offer, string $stripeEventId): int
    {
        if (! $offer->stripe_payment_intent_id) {
            return 0; // no PI yet — nothing to settle
        }

        try {
            return $this->attempt($offer, $stripeEventId);
        } catch (UniqueConstraintViolationException) {
            return (int) DB::table('settlements')
                ->where('stripe_payment_intent_id', $offer->stripe_payment_intent_id)
                ->value('id');
        }
    }

    private function attempt(SellNowOffer $offer, string $stripeEventId): int
    {
        return DB::transaction(function () use ($offer, $stripeEventId): int {
            $existing = DB::table('settlements')
                ->where('stripe_payment_intent_id', $offer->stripe_payment_intent_id)
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            $gross   = Money::fromCents((int) $offer->agreed_price_cents, $offer->currency);
            $profile = SplitProfile::fromKey('social_pilot_45_45_10');
            $result  = $this->calculator->calculate($gross, $profile);

            $settlementId = DB::table('settlements')->insertGetId([
                'stripe_payment_intent_id' => $offer->stripe_payment_intent_id,
                'stripe_event_id'          => $stripeEventId,
                'auction_id'               => null,
                'gross_cents'              => $result->gross->cents,
                'artist_cents'             => $result->artist->cents,
                'fund_cents'               => $result->fund->cents,
                'ops_cents'                => $result->ops->cents,
                'currency'                 => $result->gross->currency,
                'status'                   => 'completed',
                'profile_key'              => $result->profileKey,
                'profile_version'          => $result->profileVersion,
                'artist_bps'               => $result->artistBps,
                'fund_bps'                 => $result->fundBps,
                'ops_bps'                  => $result->opsBps,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            // Artist credit ledger entry (consignor/seller)
            DB::table('ledger_entries')->insert([
                'settlement_id'      => $settlementId,
                'settlement_line_id' => null,
                'type'               => 'credit',
                'amount_cents'       => $result->artist->cents,
                'currency'           => $result->gross->currency,
                'note'               => 'Sell Now — artist share',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // Fund credit ledger entry (impact / NGO share)
            DB::table('ledger_entries')->insert([
                'settlement_id'      => $settlementId,
                'settlement_line_id' => null,
                'type'               => 'credit',
                'amount_cents'       => $result->fund->cents,
                'currency'           => $result->gross->currency,
                'note'               => 'Sell Now — impact fund share',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            return $settlementId;
        });
    }
}
