import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { SettlementResult } from './settlement-result';
import { RecipientLine } from './recipient-line';

/**
 * Atomically persists a completed settlement.
 *
 * Idempotent on stripe_payment_intent_id: if already exists, returns existing id.
 *
 * Write order inside transaction (mirrors Laravel CreateSettlement exactly):
 *   1. settlements INSERT
 *   2. settlement_lines INSERT (one per recipient)
 *   3. ledger_entries INSERT  (one credit per line) — THREE-LEDGER INVARIANT
 *   4. transfer_outbox INSERT (one per line with a Stripe account)
 *
 * Does NOT call Stripe. The outbox worker handles that post-commit.
 */
@Injectable()
export class CreateSettlement {
  constructor(private readonly prisma: PrismaService) {}

  async execute(
    result:        SettlementResult,
    paymentIntent: string,
    stripeEvent:   string,
    recipients:    RecipientLine[],
    auctionId?:    number,
    bookPurchaseId?: number,
  ): Promise<number> {
    result.assertConservation();

    try {
      return await this.attempt(result, paymentIntent, stripeEvent, recipients, auctionId, bookPurchaseId);
    } catch (err: any) {
      // Unique constraint on stripe_payment_intent_id — concurrent request won the race
      if (err?.code === 'P2002' && err?.meta?.target?.includes('stripe_payment_intent_id')) {
        const existing = await this.prisma.settlements.findUnique({
          where: { stripe_payment_intent_id: paymentIntent },
          select: { id: true },
        });
        if (existing) return Number(existing.id);
      }
      throw err;
    }
  }

  private async attempt(
    result:          SettlementResult,
    paymentIntent:   string,
    stripeEvent:     string,
    recipients:      RecipientLine[],
    auctionId?:      number,
    bookPurchaseId?: number,
  ): Promise<number> {
    return this.prisma.$transaction(async (tx) => {
      // ── Idempotency guard ───────────────────────────────────────────────
      const existing = await tx.settlements.findUnique({
        where: { stripe_payment_intent_id: paymentIntent },
        select: { id: true },
      });
      if (existing) return Number(existing.id);

      const now = new Date();

      // ── 1. settlements ──────────────────────────────────────────────────
      const settlement = await tx.settlements.create({
        data: {
          stripe_payment_intent_id: paymentIntent,
          stripe_event_id:          stripeEvent,
          auction_id:               auctionId    ? BigInt(auctionId)    : null,
          book_purchase_id:         bookPurchaseId ? BigInt(bookPurchaseId) : null,
          gross_cents:              result.gross.cents,
          currency:                 result.gross.currency,
          profile_key:              result.profileKey,
          profile_version:          result.profileVersion,
          artist_bps:               result.artistBps,
          fund_bps:                 result.fundBps,
          ops_bps:                  result.opsBps,
          artist_cents:             result.artist.cents,
          fund_cents:               result.fund.cents,
          ops_cents:                result.ops.cents,
          status:                   'pending',
          created_at:               now,
          updated_at:               now,
        },
        select: { id: true },
      });

      const settlementId = Number(settlement.id);

      // ── 2–4. lines + ledger + outbox ────────────────────────────────────
      for (const r of recipients) {
        const line = await tx.settlement_lines.create({
          data: {
            settlement_id:      BigInt(settlementId),
            recipient_type:     r.type as any,
            legal_entity_id:    r.legalEntityId ? BigInt(r.legalEntityId) : null,
            user_id:            r.userId        ? BigInt(r.userId)         : null,
            entity_name:        r.entityName,
            entity_eik:         r.entityEik,
            entity_role:        r.entityRole,
            stripe_account_id:  r.stripeAccountId,
            amount_cents:       r.amount.cents,
            currency:           r.amount.currency,
            weight:             r.weight,
            status:             'pending',
            created_at:         now,
            updated_at:         now,
          },
          select: { id: true },
        });

        const lineId = Number(line.id);

        // ledger_entries — THREE-LEDGER INVARIANT: only credits here
        await tx.ledger_entries.create({
          data: {
            settlement_id:      BigInt(settlementId),
            settlement_line_id: BigInt(lineId),
            type:               'credit',
            amount_cents:       r.amount.cents,
            currency:           r.amount.currency,
            idempotency_key:    `settlement:${lineId}:credit`,
            note:               'Sale split',
            created_at:         now,
            updated_at:         now,
          },
        });

        if (r.stripeAccountId) {
          await tx.transfer_outbox.create({
            data: {
              settlement_line_id:     BigInt(lineId),
              stripe_account_id:      r.stripeAccountId,
              amount_cents:           r.amount.cents,
              currency:               r.amount.currency,
              stripe_idempotency_key: `${paymentIntent}_${lineId}`,
              status:                 'pending',
              attempt:                0,
              created_at:             now,
              updated_at:             now,
            },
          });
        }
      }

      return settlementId;
    });
  }
}
