import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { Money } from './money';

export interface ProcessRefundParams {
  stripeRefundId: string;
  paymentIntentId: string;
  amountCents: number;
  currency?: string;
  refundStatus?: 'pending' | 'succeeded' | 'failed' | 'canceled';
  chargeId?: string | null;
  reason?: string | null;
}

export interface ProcessRefundResult {
  refundId: bigint;
  reversalOrderIds: bigint[];
}

// refund_lines.recipient_type is a narrower enum than settlement_lines.recipient_type.
const REFUNDABLE_RECIPIENTS = new Set(['artist', 'fund', 'ops']);

/**
 * Port of Laravel ProcessRefund. Idempotent on stripe_refund_id.
 *
 * Only 'succeeded' refunds change financial state; other statuses are stored for audit.
 * Cumulative partial refunds allocate the cumulative total across the frozen original
 * settlement lines and write only the delta, so 33.33 + 66.66 equals one 99.99 refund.
 * Ledger debits are per refund line, written here and never by the reversal worker.
 * THREE-LEDGER INVARIANT: only ledger_entries is written.
 */
@Injectable()
export class RefundService {
  private readonly logger = new Logger(RefundService.name);

  constructor(private readonly prisma: PrismaService) {}

  async process(p: ProcessRefundParams): Promise<ProcessRefundResult> {
    const currency = (p.currency ?? 'EUR').toUpperCase();
    const refundStatus = p.refundStatus ?? 'succeeded';
    const active = refundStatus === 'succeeded';
    const reversalOrderIds: bigint[] = [];

    const refundId = await this.prisma.$transaction(async (tx) => {
      const locked = await tx.$queryRaw<{ id: bigint; currency: string; gross_cents: number }[]>`
        SELECT id, currency, gross_cents FROM settlements
        WHERE stripe_payment_intent_id = ${p.paymentIntentId}
        FOR UPDATE
      `;
      const settlement = locked[0];
      if (!settlement) throw new Error(`No settlement found for payment_intent ${p.paymentIntentId}.`);

      const gross = Number(settlement.gross_cents);
      if (currency !== String(settlement.currency).toUpperCase()) {
        throw new Error(`Currency mismatch: refund is ${currency}, settlement is ${settlement.currency}.`);
      }

      const existing = await tx.refunds.findUnique({
        where: { stripe_refund_id: p.stripeRefundId },
        select: { id: true },
      });
      if (existing) return existing.id;

      const priorSucceeded = Number(
        (await tx.refunds.aggregate({
          _sum: { amount_cents: true },
          where: { settlement_id: settlement.id, refund_status: 'succeeded' as any },
        }))._sum.amount_cents ?? 0,
      );

      if (active && priorSucceeded + p.amountCents > gross) {
        throw new Error(
          `Cumulative refund ${priorSucceeded + p.amountCents} exceeds gross ${gross} for settlement ${settlement.id}.`,
        );
      }

      const now = new Date();
      const isPartial = p.amountCents < gross;
      const refund = await tx.refunds.create({
        data: {
          stripe_refund_id: p.stripeRefundId,
          stripe_charge_id: p.chargeId ?? null,
          settlement_id: settlement.id,
          amount_cents: p.amountCents,
          currency,
          is_partial: isPartial,
          refund_status: refundStatus as any,
          reconciliation_status: (active ? 'pending' : 'not_required') as any,
          reason: p.reason ?? null,
          created_at: now,
          updated_at: now,
        },
        select: { id: true },
      });

      if (!active) return refund.id;

      const lines = await tx.settlement_lines.findMany({
        where: { settlement_id: settlement.id },
        orderBy: { id: 'asc' },
        select: { id: true, amount_cents: true, recipient_type: true, status: true, stripe_transfer_id: true },
      });
      if (lines.length === 0) throw new Error(`Settlement ${settlement.id} has no lines to refund.`);

      const cumulative = Money.fromCents(priorSucceeded + p.amountCents, currency).allocate(
        lines.map((l) => Number(l.amount_cents)),
      );

      const prevLines = await tx.refund_lines.findMany({
        where: { refund_id: { not: refund.id }, refunds: { settlement_id: settlement.id, refund_status: 'succeeded' as any } },
        select: { settlement_line_id: true, amount_cents: true },
      });
      const prevByLine = new Map<string, number>();
      for (const pl of prevLines) {
        const k = String(pl.settlement_line_id);
        prevByLine.set(k, (prevByLine.get(k) ?? 0) + Number(pl.amount_cents));
      }

      for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const delta = cumulative[i].cents - (prevByLine.get(String(line.id)) ?? 0);
        if (delta <= 0) continue;

        if (!REFUNDABLE_RECIPIENTS.has(String(line.recipient_type))) {
          throw new Error(`Refund of ${line.recipient_type} settlement line ${line.id} is not supported (refund_lines enum).`);
        }

        const refundLine = await tx.refund_lines.create({
          data: {
            refund_id: refund.id,
            settlement_line_id: line.id,
            amount_cents: delta,
            currency,
            recipient_type: line.recipient_type as any,
            status: 'pending' as any,
            created_at: now,
            updated_at: now,
          },
          select: { id: true },
        });

        await tx.ledger_entries.create({
          data: {
            settlement_id: settlement.id,
            settlement_line_id: line.id,
            refund_line_id: refundLine.id,
            type: 'debit' as any,
            amount_cents: delta,
            currency,
            idempotency_key: `refund:${refundLine.id}:debit`,
            note: isPartial ? 'Partial refund' : 'Full refund',
            created_at: now,
            updated_at: now,
          },
        });

        if (line.status === 'pending') {
          await tx.settlement_lines.update({ where: { id: line.id }, data: { status: 'canceled' as any, updated_at: now } });
          await tx.transfer_outbox.updateMany({
            where: { settlement_line_id: line.id, status: 'pending' as any },
            data: { status: 'canceled' as any, last_error: 'Canceled: settlement refunded', updated_at: now },
          });
        } else if (line.status === 'processing') {
          // The transfer worker holds this line; the transfer id is recorded when it commits.
          await tx.settlement_lines.update({ where: { id: line.id }, data: { status: 'reversal_pending' as any, updated_at: now } });
        } else if (line.status === 'transferred' && line.stripe_transfer_id) {
          await tx.settlement_lines.update({ where: { id: line.id }, data: { status: 'reversal_pending' as any, updated_at: now } });
          const order = await tx.transfer_reversal_orders.create({
            data: {
              refund_line_id: refundLine.id,
              stripe_transfer_id: line.stripe_transfer_id,
              amount_cents: delta,
              currency,
              idempotency_key: `rev_${p.stripeRefundId}_line_${line.id}`,
              status: 'pending' as any,
              attempt: 0,
              created_at: now,
              updated_at: now,
            },
            select: { id: true },
          });
          reversalOrderIds.push(order.id);
        }
      }

      await tx.settlements.update({
        where: { id: settlement.id },
        data: { status: (priorSucceeded + p.amountCents >= gross ? 'refunded' : 'partially_refunded') as any, updated_at: now },
      });

      return refund.id;
    });

    if (reversalOrderIds.length > 0) {
      this.logger.log(`refund ${p.stripeRefundId}: ${reversalOrderIds.length} transfer reversal order(s) queued`);
    }
    return { refundId, reversalOrderIds };
  }
}
