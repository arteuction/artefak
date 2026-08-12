<?php

declare(strict_types=1);

namespace App\Domain\Library;

use App\Domain\Settlement\CoAuthorSplit;
use App\Domain\Settlement\Money;
use App\Models\BookAuthor;
use App\Models\BookPurchase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Processes a verified Stripe payment for a book purchase.
 *
 * Called from the webhook inbox after signature verification.
 * Idempotent: a duplicate webhook for the same PaymentIntent is a safe no-op.
 *
 * Transaction contract (all-or-nothing):
 *   1. Lock book_purchase row (SELECT ... FOR UPDATE)
 *   2. Verify amount, currency, status=pending
 *   3. Mark paid + freeze split snapshot
 *   4. Grant entitlement
 *   5. Create settlement + lines + ledger + outbox
 *   6. Commit
 *
 * Does NOT call Stripe. Stripe interaction happens before this action is invoked.
 */
final class FinalizePaidBookPurchase
{
    public function __construct(
        private readonly BookSettlementCalculator $calculator,
        private readonly CreateBookSettlement     $createSettlement,
        private readonly GrantBookEntitlement     $grantEntitlement,
    ) {}

    /**
     * @param  string $stripePaymentIntentId  From verified Stripe webhook
     * @param  string $stripeEventId          From verified Stripe webhook
     * @param  int    $amountCents            Amount Stripe confirms was paid
     * @param  string $currency               Must match purchase currency
     * @throws RuntimeException               On amount/currency mismatch or unexpected status
     */
    public function execute(
        string $stripePaymentIntentId,
        string $stripeEventId,
        int    $amountCents,
        string $currency,
    ): BookPurchase {
        return DB::transaction(function () use (
            $stripePaymentIntentId, $stripeEventId, $amountCents, $currency
        ): BookPurchase {

            // ── Idempotency: already paid → return existing ───────────────────
            $existing = BookPurchase::where('stripe_payment_intent_id', $stripePaymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw new RuntimeException(
                    "No book_purchase found for PaymentIntent [{$stripePaymentIntentId}]."
                );
            }

            if ($existing->status === 'paid') {
                return $existing; // idempotent — duplicate webhook
            }

            if ($existing->status !== 'pending') {
                throw new RuntimeException(
                    "Cannot finalise purchase [{$existing->id}] in status [{$existing->status}]."
                );
            }

            // ── Verify amount and currency ────────────────────────────────────
            if ($existing->currency !== strtoupper($currency)) {
                throw new RuntimeException(
                    "Currency mismatch: expected [{$existing->currency}], Stripe sent [{$currency}]."
                );
            }

            if ($existing->gross_cents !== $amountCents) {
                throw new RuntimeException(
                    "Amount mismatch: expected [{$existing->gross_cents}], Stripe confirmed [{$amountCents}]."
                );
            }

            // ── Calculate split on split_base_cents ───────────────────────────
            $result = $this->calculator->calculate(
                splitBaseCents: $existing->split_base_cents,
                profileKey:     $existing->profile_key,
                currency:       $existing->currency,
            );

            // ── Mark paid, freeze snapshot ────────────────────────────────────
            $existing->update([
                'status'                   => 'paid',
                'stripe_payment_intent_id' => $stripePaymentIntentId,
                'paid_at'                  => now(),
                // Freeze the real bps used (in case profile was updated after checkout)
                'author_bps'               => $result->authorBps,
                'fund_bps'                 => $result->fundBps,
                'ops_bps'                  => $result->opsBps,
            ]);

            // ── Grant entitlement ─────────────────────────────────────────────
            $this->grantEntitlement->forPurchase($existing);

            // ── Build co-author weights from book_authors ─────────────────────
            $bookAuthors = BookAuthor::where('book_id', $existing->book_id)
                ->get()
                ->keyBy('author_id');

            $authorWeights = $bookAuthors->isEmpty()
                ? [(string) $existing->book->owner_id => 10000]
                : $bookAuthors->mapWithKeys(fn ($ba) => [(string) $ba->author_id => $ba->share_bps])->all();

            $authorSplits = CoAuthorSplit::split($result->author, $authorWeights);

            // ── Create settlement with lines, ledger, outbox ──────────────────
            $this->createSettlement->execute(
                purchase:     $existing,
                result:       $result,
                stripeEvent:  $stripeEventId,
                authorSplits: $authorSplits,
            );

            return $existing->fresh();
        });
    }
}
