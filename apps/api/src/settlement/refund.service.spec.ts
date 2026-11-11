/**
 * RefundService — port of Laravel ProcessRefund (mocked Prisma; no DB, no Stripe)
 *
 * RF1  unknown payment_intent: throws, nothing written
 * RF2  currency mismatch: throws, nothing written
 * RF3  duplicate stripe_refund_id: returns existing id, nothing written (idempotent replay)
 * RF4  non-succeeded refund: audit row only, reconciliation not_required, no lines/ledger
 * RF5  full refund, all lines pending: allocation conserves the amount, per-line debit, lines+outbox canceled, settlement refunded
 * RF6  second partial refund writes only the cumulative delta (33.33 + 66.66 == 99.99, no lost cent)
 * RF7  cumulative refund above gross: throws, nothing written
 * RF8  line states: transferred -> reversal order, processing -> reversal_pending, pending -> canceled
 * RF9  unsupported refund recipient (author/co_author): throws
 * RF10 partial refund below gross: settlement partially_refunded
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { RefundService } from './refund.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const mockTx = {
  $queryRaw:           fn(),
  refunds:             { findUnique: fn(), aggregate: fn(), create: fn() },
  settlement_lines:    { findMany: fn(), update: fn() },
  refund_lines:        { findMany: fn(), create: fn() },
  ledger_entries:      { create: fn() },
  transfer_outbox:     { updateMany: fn() },
  transfer_reversal_orders: { create: fn() },
  settlements:         { update: fn() },
};
const mockPrisma = { $transaction: fn() };

const SETTLEMENT = { id: BigInt(1), currency: 'EUR', gross_cents: 10000 };
// 45 / 45 / 10 split of 10000
const lines = (over: Partial<Record<number, any>> = {}) => [
  { id: BigInt(101), amount_cents: 4500, recipient_type: 'artist', status: 'pending', stripe_transfer_id: null, ...over[0] },
  { id: BigInt(102), amount_cents: 4500, recipient_type: 'fund',   status: 'pending', stripe_transfer_id: null, ...over[1] },
  { id: BigInt(103), amount_cents: 1000, recipient_type: 'ops',    status: 'pending', stripe_transfer_id: null, ...over[2] },
];

function arrange(o: {
  settlement?: any; existing?: any; prior?: number; lines?: any[]; prevLines?: any[];
} = {}) {
  mockTx.$queryRaw.mockResolvedValue(o.settlement === undefined ? [SETTLEMENT] : o.settlement ? [o.settlement] : []);
  mockTx.refunds.findUnique.mockResolvedValue(o.existing ?? null);
  mockTx.refunds.aggregate.mockResolvedValue({ _sum: { amount_cents: o.prior ?? null } });
  mockTx.refunds.create.mockResolvedValue({ id: BigInt(500) });
  mockTx.settlement_lines.findMany.mockResolvedValue(o.lines ?? lines());
  mockTx.refund_lines.findMany.mockResolvedValue(o.prevLines ?? []);
  let n = 900;
  mockTx.refund_lines.create.mockImplementation(async () => ({ id: BigInt(++n) }));
  mockTx.ledger_entries.create.mockResolvedValue({});
  mockTx.transfer_outbox.updateMany.mockResolvedValue({ count: 1 });
  mockTx.transfer_reversal_orders.create.mockResolvedValue({ id: BigInt(700) });
  mockTx.settlements.update.mockResolvedValue({});
  mockTx.settlement_lines.update.mockResolvedValue({});
}

const P = { stripeRefundId: 're_1', paymentIntentId: 'pi_1', amountCents: 10000 };
const written = () =>
  mockTx.refunds.create.mock.calls.length + mockTx.refund_lines.create.mock.calls.length +
  mockTx.ledger_entries.create.mock.calls.length + mockTx.settlements.update.mock.calls.length;
const refundLineAmounts = () => (mockTx.refund_lines.create.mock.calls as any[]).map((c) => c[0].data.amount_cents);

describe('RefundService', () => {
  let svc: RefundService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [RefundService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(RefundService);
  });

  beforeEach(() => {
    jest.resetAllMocks();
    mockPrisma.$transaction.mockImplementation(async (cb: any) => cb(mockTx));
  });

  it('RF1: unknown payment_intent — throws, nothing written', async () => {
    arrange({ settlement: null });
    await expect(svc.process(P)).rejects.toThrow('No settlement found');
    expect(written()).toBe(0);
  });

  it('RF2: currency mismatch — throws, nothing written', async () => {
    arrange();
    await expect(svc.process({ ...P, currency: 'USD' })).rejects.toThrow('Currency mismatch');
    expect(written()).toBe(0);
  });

  it('RF3: duplicate stripe_refund_id — returns existing id, nothing written', async () => {
    arrange({ existing: { id: BigInt(42) } });
    const r = await svc.process(P);
    expect(r.refundId).toBe(BigInt(42));
    expect(r.reversalOrderIds).toEqual([]);
    expect(written()).toBe(0);
  });

  it('RF4: non-succeeded refund — audit row only, no lines/ledger/settlement change', async () => {
    arrange();
    await svc.process({ ...P, refundStatus: 'pending' });
    const data = (mockTx.refunds.create.mock.calls[0] as any)[0].data;
    expect(data.refund_status).toBe('pending');
    expect(data.reconciliation_status).toBe('not_required');
    expect(mockTx.refund_lines.create).not.toHaveBeenCalled();
    expect(mockTx.ledger_entries.create).not.toHaveBeenCalled();
    expect(mockTx.settlements.update).not.toHaveBeenCalled();
  });

  it('RF5: full refund, all lines pending — conserves amount, per-line debit, cancels lines + outbox, refunded', async () => {
    arrange();
    await svc.process(P);

    const amounts = refundLineAmounts();
    expect(amounts).toEqual([4500, 4500, 1000]);
    expect(amounts.reduce((a: number, b: number) => a + b, 0)).toBe(10000); // conservation

    const ledger = (mockTx.ledger_entries.create.mock.calls as any[]).map((c) => c[0].data);
    expect(ledger).toHaveLength(3);
    expect(ledger.every((d) => d.type === 'debit')).toBe(true);
    expect(ledger.map((d) => d.idempotency_key)).toEqual(['refund:901:debit', 'refund:902:debit', 'refund:903:debit']);
    expect(ledger.map((d) => d.refund_line_id)).toEqual([BigInt(901), BigInt(902), BigInt(903)]);

    expect(mockTx.settlement_lines.update).toHaveBeenCalledTimes(3);
    expect((mockTx.settlement_lines.update.mock.calls as any[]).every((c) => c[0].data.status === 'canceled')).toBe(true);
    expect(mockTx.transfer_outbox.updateMany).toHaveBeenCalledTimes(3);
    expect((mockTx.settlements.update.mock.calls[0] as any)[0].data.status).toBe('refunded');
  });

  it('RF6: second partial refund writes only the cumulative delta — no lost cent', async () => {
    // First refund 3333 was allocated 1500 / 1500 / 333 (largest remainder). Now +6666 => cumulative 9999.
    arrange({
      prior: 3333,
      prevLines: [
        { settlement_line_id: BigInt(101), amount_cents: 1500 },
        { settlement_line_id: BigInt(102), amount_cents: 1500 },
        { settlement_line_id: BigInt(103), amount_cents: 333 },
      ],
    });
    await svc.process({ ...P, stripeRefundId: 're_2', amountCents: 6666 });

    const deltas = refundLineAmounts();
    expect(deltas).toEqual([3000, 2999, 667]);
    expect(deltas.reduce((a: number, b: number) => a + b, 0)).toBe(6666);
    // Together with the first refund the lines hold exactly the cumulative allocation of 9999.
    expect([1500 + 3000, 1500 + 2999, 333 + 667]).toEqual([4500, 4499, 1000]);
    expect((mockTx.settlements.update.mock.calls[0] as any)[0].data.status).toBe('partially_refunded');
  });

  it('RF7: cumulative refund above gross — throws, nothing written', async () => {
    arrange({ prior: 9000 });
    await expect(svc.process({ ...P, amountCents: 2000 })).rejects.toThrow('exceeds gross');
    expect(written()).toBe(0);
  });

  it('RF8: line states — transferred gets a reversal order, processing reversal_pending, pending canceled', async () => {
    arrange({
      lines: lines({
        0: { status: 'transferred', stripe_transfer_id: 'tr_artist' },
        1: { status: 'processing' },
        2: { status: 'pending' },
      }),
    });
    const r = await svc.process(P);

    const upd = (mockTx.settlement_lines.update.mock.calls as any[]).map((c) => [c[0].where.id, c[0].data.status]);
    expect(upd).toEqual([[BigInt(101), 'reversal_pending'], [BigInt(102), 'reversal_pending'], [BigInt(103), 'canceled']]);

    expect(mockTx.transfer_reversal_orders.create).toHaveBeenCalledTimes(1);
    const order = (mockTx.transfer_reversal_orders.create.mock.calls[0] as any)[0].data;
    expect(order.stripe_transfer_id).toBe('tr_artist');
    expect(order.amount_cents).toBe(4500);
    expect(order.idempotency_key).toBe('rev_re_1_line_101');
    expect(order.status).toBe('pending');
    expect(r.reversalOrderIds).toEqual([BigInt(700)]);
    // Only the pending line's outbox row is canceled.
    expect(mockTx.transfer_outbox.updateMany).toHaveBeenCalledTimes(1);
  });

  it('RF9: unsupported refund recipient — throws', async () => {
    arrange({ lines: lines({ 0: { recipient_type: 'author' } }) });
    await expect(svc.process(P)).rejects.toThrow('not supported');
  });

  it('RF10: partial refund below gross — is_partial and partially_refunded', async () => {
    arrange();
    await svc.process({ ...P, amountCents: 5000 });
    expect((mockTx.refunds.create.mock.calls[0] as any)[0].data.is_partial).toBe(true);
    expect((mockTx.settlements.update.mock.calls[0] as any)[0].data.status).toBe('partially_refunded');
  });
});
