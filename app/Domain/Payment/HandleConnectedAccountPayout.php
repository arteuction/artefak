<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\ConnectedAccountPayout;

/**
 * Records a Stripe connected-account payout event idempotently.
 *
 * Stripe Payouts move funds from a connected account to its bank. They are distinct
 * from Transfers (platform → connected account). A single payout may cover many
 * transfers, so we never update individual settlement lines here — reconciliation
 * correlates payouts to transfers separately.
 */
class HandleConnectedAccountPayout
{
    /**
     * @param  string  $stripePayoutId  e.g. "po_xxx"
     * @param  string  $stripeAccountId e.g. "acct_xxx"
     * @param  string  $stripeEventId   webhook event id for idempotency
     * @param  int     $amountCents
     * @param  string  $currency        uppercase, e.g. "BGN"
     * @param  string  $status          "paid" | "failed" | "canceled"
     * @param  string|null $failureCode
     * @param  string|null $failureMessage
     * @param  int|null $arrivalDate    Unix timestamp from Stripe
     */
    public function execute(
        string $stripePayoutId,
        string $stripeAccountId,
        string $stripeEventId,
        int $amountCents,
        string $currency,
        string $status,
        ?string $failureCode = null,
        ?string $failureMessage = null,
        ?int $arrivalDate = null,
    ): ConnectedAccountPayout {
        // Idempotent: if this payout was already recorded, return it unchanged.
        $existing = ConnectedAccountPayout::where('stripe_payout_id', $stripePayoutId)->first();
        if ($existing !== null) {
            return $existing;
        }

        return ConnectedAccountPayout::create([
            'stripe_payout_id'  => $stripePayoutId,
            'stripe_account_id' => $stripeAccountId,
            'stripe_event_id'   => $stripeEventId,
            'amount_cents'      => $amountCents,
            'currency'          => $currency,
            'status'            => $status,
            'failure_code'      => $failureCode,
            'failure_message'   => $failureMessage,
            'arrival_date'      => $arrivalDate !== null ? \Carbon\Carbon::createFromTimestamp($arrivalDate) : null,
        ]);
    }
}
