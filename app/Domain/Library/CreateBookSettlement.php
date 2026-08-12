<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Domain\Settlement\Money;
use App\Models\BookPurchase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persists a book-sale settlement atomically.
 *
 * Idempotent: if a settlement already exists for this stripe_payment_intent_id,
 * returns the existing ID without touching any rows.
 *
 * Lines:
 *   - One row per author (type = 'author')
 *   - One row for the SDG fund (type = 'fund')
 *   - One row for platform operations (type = 'ops')
 *
 * Each line gets a ledger credit and, if a Stripe Connect account is configured,
 * an outbox row for the transfer worker.
 */
final class CreateBookSettlement
{
    /**
     * @param  array<string, Money> $authorSplits  [user_id => Money]
     */
    public function execute(
        BookPurchase         $purchase,
        BookSettlementResult $result,
        string               $stripeEvent,
        array                $authorSplits,
    ): int {
        try {
            return $this->attempt($purchase, $result, $stripeEvent, $authorSplits);
        } catch (UniqueConstraintViolationException) {
            return (int) DB::table('settlements')
                ->where('stripe_payment_intent_id', $purchase->stripe_payment_intent_id)
                ->value('id');
        }
    }

    private function attempt(
        BookPurchase         $purchase,
        BookSettlementResult $result,
        string               $stripeEvent,
        array                $authorSplits,
    ): int {
        return DB::transaction(function () use ($purchase, $result, $stripeEvent, $authorSplits): int {

            // Idempotency guard inside the transaction
            $existing = DB::table('settlements')
                ->where('stripe_payment_intent_id', $purchase->stripe_payment_intent_id)
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            // ── 1. settlements ────────────────────────────────────────────────
            $settlementId = (int) DB::table('settlements')->insertGetId([
                'stripe_payment_intent_id' => $purchase->stripe_payment_intent_id,
                'stripe_event_id'          => $stripeEvent,
                'book_purchase_id'         => $purchase->id,
                // Use split_base as "gross" for this settlement — tax/fee are excluded
                'gross_cents'              => $result->splitBase->cents,
                'currency'                 => $result->splitBase->currency,
                'profile_key'              => $result->profileKey,
                'profile_version'          => $result->profileVersion,
                'artist_bps'               => $result->authorBps,
                'fund_bps'                 => $result->fundBps,
                'ops_bps'                  => $result->opsBps,
                'artist_cents'             => $result->author->cents,
                'fund_cents'               => $result->fund->cents,
                'ops_cents'                => $result->ops->cents,
                'status'                   => 'pending',
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

            // ── 2. Author lines (one per co-author) ───────────────────────────
            foreach ($authorSplits as $authorId => $authorMoney) {
                $this->insertLine(
                    settlementId:    $settlementId,
                    piId:            $purchase->stripe_payment_intent_id,
                    recipientType:   'author',
                    legalEntityId:   (int) $authorId,
                    amount:          $authorMoney,
                    weight:          $authorMoney->cents,
                );
            }

            // ── 3. Fund line ──────────────────────────────────────────────────
            $this->insertLine(
                settlementId:    $settlementId,
                piId:            $purchase->stripe_payment_intent_id,
                recipientType:   'fund',
                legalEntityId:   null,
                amount:          $result->fund,
                weight:          1,
            );

            // ── 4. Ops line ───────────────────────────────────────────────────
            $this->insertLine(
                settlementId:    $settlementId,
                piId:            $purchase->stripe_payment_intent_id,
                recipientType:   'ops',
                legalEntityId:   null,
                amount:          $result->ops,
                weight:          1,
            );

            return $settlementId;
        });
    }

    private function insertLine(
        int     $settlementId,
        string  $piId,
        string  $recipientType,
        ?int    $legalEntityId,
        Money   $amount,
        int     $weight,
    ): void {
        $lineId = (int) DB::table('settlement_lines')->insertGetId([
            'settlement_id'     => $settlementId,
            'recipient_type'    => $recipientType,
            'legal_entity_id'   => $legalEntityId,
            'amount_cents'      => $amount->cents,
            'currency'          => $amount->currency,
            'weight'            => $weight,
            'status'            => 'pending',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('ledger_entries')->insert([
            'settlement_id'      => $settlementId,
            'settlement_line_id' => $lineId,
            'type'               => 'credit',
            'amount_cents'       => $amount->cents,
            'currency'           => $amount->currency,
            'idempotency_key'    => "book_settlement:{$lineId}:credit",
            'note'               => 'Book sale split',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}
