<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Atomically persists a completed settlement.
 *
 * Idempotent: if stripe_payment_intent_id already exists, returns the
 * existing settlement ID without touching any other rows.
 *
 * Call order inside the transaction:
 *   1. INSERT settlements (or bail if duplicate → idempotent return)
 *   2. INSERT settlement_lines (one per recipient)
 *   3. INSERT ledger_entries  (one credit per fund line)
 *   4. INSERT transfer_outbox (one row per line with a Stripe account)
 *
 * Does NOT call Stripe. The outbox worker handles that post-commit.
 */
final class CreateSettlement
{
    /**
     * @param  SettlementResult            $result        Computed split
     * @param  string                      $paymentIntent stripe_payment_intent_id
     * @param  string                      $stripeEvent   stripe_event_id
     * @param  array<RecipientLine>        $recipients    Lines to persist
     * @param  int|null                    $auctionId
     * @return int  settlements.id (existing or newly created)
     */
    public function execute(
        SettlementResult $result,
        string           $paymentIntent,
        string           $stripeEvent,
        array            $recipients,
        ?int             $auctionId = null,
    ): int {
        try {
            return $this->attempt($result, $paymentIntent, $stripeEvent, $recipients, $auctionId);
        } catch (UniqueConstraintViolationException) {
            // Concurrent request won the INSERT race — fetch and return the winner.
            return (int) DB::table('settlements')
                ->where('stripe_payment_intent_id', $paymentIntent)
                ->value('id');
        }
    }

    private function attempt(
        SettlementResult $result,
        string           $paymentIntent,
        string           $stripeEvent,
        array            $recipients,
        ?int             $auctionId,
    ): int {
        return DB::transaction(function () use ($result, $paymentIntent, $stripeEvent, $recipients, $auctionId): int {
            // ── Idempotency guard ─────────────────────────────────────────
            $existing = DB::table('settlements')
                ->where('stripe_payment_intent_id', $paymentIntent)
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            // ── 1. settlements ────────────────────────────────────────────
            $settlementId = (int) DB::table('settlements')->insertGetId([
                'stripe_payment_intent_id' => $paymentIntent,
                'stripe_event_id'          => $stripeEvent,
                'auction_id'               => $auctionId,
                'gross_cents'              => $result->gross->cents,
                'currency'                 => $result->gross->currency,
                'profile_key'              => $result->profileKey,
                'profile_version'          => $result->profileVersion,
                'artist_bps'               => $result->artistBps,
                'fund_bps'                 => $result->fundBps,
                'ops_bps'                  => $result->opsBps,
                'artist_cents'             => $result->artist->cents,
                'fund_cents'               => $result->fund->cents,
                'ops_cents'                => $result->ops->cents,
                'status'                   => 'pending',
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            // ── 2–4. lines + ledger + outbox ──────────────────────────────
            foreach ($recipients as $recipient) {
                $lineId = (int) DB::table('settlement_lines')->insertGetId([
                    'settlement_id'    => $settlementId,
                    'recipient_type'   => $recipient->type,
                    'legal_entity_id'  => $recipient->legalEntityId,
                    'entity_name'      => $recipient->entityName,
                    'entity_eik'       => $recipient->entityEik,
                    'entity_role'      => $recipient->entityRole,
                    'stripe_account_id'=> $recipient->stripeAccountId,
                    'amount_cents'     => $recipient->amount->cents,
                    'currency'         => $recipient->amount->currency,
                    'weight'           => $recipient->weight,
                    'status'           => 'pending',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                // Ledger credit for fund lines only (append-only fund balance)
                if ($recipient->type === 'fund') {
                    DB::table('ledger_entries')->insert([
                        'settlement_id'      => $settlementId,
                        'settlement_line_id' => $lineId,
                        'type'               => 'credit',
                        'amount_cents'       => $recipient->amount->cents,
                        'currency'           => $recipient->amount->currency,
                        'note'               => 'Sale split',
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }

                // Outbox row for any line that has a Stripe Connect account
                if ($recipient->stripeAccountId !== null) {
                    DB::table('transfer_outbox')->insert([
                        'settlement_line_id'     => $lineId,
                        'stripe_account_id'      => $recipient->stripeAccountId,
                        'amount_cents'            => $recipient->amount->cents,
                        'currency'               => $recipient->amount->currency,
                        'stripe_idempotency_key' => $paymentIntent . '_' . $lineId,
                        'status'                 => 'pending',
                        'attempt'                => 0,
                        'created_at'             => now(),
                        'updated_at'             => now(),
                    ]);
                }
            }

            return $settlementId;
        });
    }
}
