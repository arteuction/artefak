/**
 * Phase 0.5 — SettlementService + CreateSettlement
 *
 * SS1  settle — calculates + persists; returns settlement id
 * SS2  settle — idempotent: second call with same paymentIntent returns existing id
 * SS3  settle — three-ledger invariant: ledger_entries receive exactly one credit per line
 * SS4  settle — transfer_outbox row written for lines with stripeAccountId
 * SS5  settle — no outbox row for lines without stripeAccountId
 * SS6  settle — conservation: funds that go into DB sum to grossCents
 * SS7  preview — returns split without writing
 * SS8  preview — unknown profileKey throws
 * SS9  getSettlement — returns settlement with lines
 * SS10 getSettlement — not found throws NotFoundException
 * SS11 listSettlements — returns paged result
 * SS12 settle — Prisma P2002 unique violation is handled idempotently
 * SS13 getSettlementLines — found: returns lines array
 * SS14 getSettlementLines — empty lines + settlement not found: throws NotFoundException
 * SS15 getLedgerEntries — queries ledger_entries by settlement_id
 * SS16 getTransferOutbox — returns outbox rows keyed by settlement_line_id
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { SettlementService } from './settlement.service';
import { CreateSettlement } from './create-settlement';
import { PrismaService } from '../prisma.service';
import { RecipientLine } from './recipient-line';
import { Money } from './money';

const fn = () => jest.fn() as any;

// Minimal Prisma mock — settlement tables only
const mockPrisma = {
  settlements: {
    create:     fn(),
    findUnique: fn(),
    findMany:   fn(),
    count:      fn(),
  },
  settlement_lines: {
    create:   fn(),
    findMany: fn(),
  },
  ledger_entries: {
    create:   fn(),
    findMany: fn(),
  },
  transfer_outbox: {
    create:   fn(),
    findMany: fn(),
  },
  $transaction: fn(),
};

const ARTIST_LINE: RecipientLine = {
  type: 'artist', legalEntityId: null, userId: 1,
  entityName: 'Ivan Petrov', entityEik: null, entityRole: 'artist',
  stripeAccountId: 'acct_artist', amount: Money.fromCents(4500), weight: 4500,
};
const FUND_LINE: RecipientLine = {
  type: 'fund', legalEntityId: 42, userId: null,
  entityName: 'Art Fund', entityEik: '123456789', entityRole: 'fund',
  stripeAccountId: null, amount: Money.fromCents(4500), weight: 4500,
};
const OPS_LINE: RecipientLine = {
  type: 'platform', legalEntityId: null, userId: null,
  entityName: 'ARTeuCtion', entityEik: null, entityRole: 'platform',
  stripeAccountId: 'acct_platform', amount: Money.fromCents(1000), weight: 1000,
};

const STUB_SETTLEMENT = {
  id: BigInt(1), stripe_payment_intent_id: 'pi_test', stripe_event_id: 'evt_1',
  gross_cents: 10000, currency: 'EUR', profile_key: 'social_pilot_45_45_10',
  profile_version: 1, artist_bps: 4500, fund_bps: 4500, ops_bps: 1000,
  artist_cents: 4500, fund_cents: 4500, ops_cents: 1000,
  status: 'pending', created_at: new Date(), updated_at: new Date(),
  settlement_lines: [], ledger_entries: [],
};

describe('Phase 0.5 — SettlementService', () => {
  let svc: SettlementService;
  let writer: CreateSettlement;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        SettlementService,
        CreateSettlement,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc    = module.get(SettlementService);
    writer = module.get(CreateSettlement);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('SS1: settle — returns settlement id', async () => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
      mockPrisma.settlements.create.mockResolvedValueOnce({ id: BigInt(1) });
      mockPrisma.settlement_lines.create
        .mockResolvedValueOnce({ id: BigInt(1) })
        .mockResolvedValueOnce({ id: BigInt(2) })
        .mockResolvedValueOnce({ id: BigInt(3) });
      mockPrisma.ledger_entries.create.mockResolvedValue(undefined);
      mockPrisma.transfer_outbox.create.mockResolvedValue(undefined);
      return fn(mockPrisma);
    });

    const id = await svc.settle({
      grossCents: 10000, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_test', stripeEvent: 'evt_1',
      recipients: [ARTIST_LINE, FUND_LINE, OPS_LINE],
    });
    expect(id).toBe(1);
  });

  it('SS2: settle — idempotent: existing row returned without double-write', async () => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockPrisma.settlements.findUnique.mockResolvedValueOnce({ id: BigInt(7) });
      return fn(mockPrisma);
    });

    const id = await svc.settle({
      grossCents: 10000, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_existing', stripeEvent: 'evt_1',
      recipients: [ARTIST_LINE],
    });
    expect(id).toBe(7);
    expect(mockPrisma.settlements.create).not.toHaveBeenCalled();
  });

  it('SS3: three-ledger invariant — one credit per recipient line', async () => {
    const ledgerCreates: any[] = [];
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
      mockPrisma.settlements.create.mockResolvedValueOnce({ id: BigInt(1) });
      mockPrisma.settlement_lines.create
        .mockResolvedValueOnce({ id: BigInt(1) })
        .mockResolvedValueOnce({ id: BigInt(2) })
        .mockResolvedValueOnce({ id: BigInt(3) });
      mockPrisma.ledger_entries.create.mockImplementation(async (args: any) => {
        ledgerCreates.push(args.data);
      });
      mockPrisma.transfer_outbox.create.mockResolvedValue(undefined);
      return fn(mockPrisma);
    });

    await svc.settle({
      grossCents: 10000, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_test2', stripeEvent: 'evt_2',
      recipients: [ARTIST_LINE, FUND_LINE, OPS_LINE],
    });

    expect(ledgerCreates).toHaveLength(3);
    expect(ledgerCreates.every((e: any) => e.type === 'credit')).toBe(true);
    // All three entries belong to settlements, not donations or impact_events
    expect(ledgerCreates.every((e: any) => e.settlement_id !== undefined)).toBe(true);
  });

  it('SS4: outbox row written for lines with stripeAccountId', async () => {
    const outboxCreates: any[] = [];
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
      mockPrisma.settlements.create.mockResolvedValueOnce({ id: BigInt(1) });
      mockPrisma.settlement_lines.create
        .mockResolvedValueOnce({ id: BigInt(1) })
        .mockResolvedValueOnce({ id: BigInt(2) })
        .mockResolvedValueOnce({ id: BigInt(3) });
      mockPrisma.ledger_entries.create.mockResolvedValue(undefined);
      mockPrisma.transfer_outbox.create.mockImplementation(async (args: any) => {
        outboxCreates.push(args.data);
      });
      return fn(mockPrisma);
    });

    await svc.settle({
      grossCents: 10000, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_test3', stripeEvent: 'evt_3',
      recipients: [ARTIST_LINE, FUND_LINE, OPS_LINE],
    });

    // ARTIST_LINE and OPS_LINE have stripeAccountId; FUND_LINE does not
    expect(outboxCreates).toHaveLength(2);
    expect(outboxCreates[0].stripe_account_id).toBe('acct_artist');
    expect(outboxCreates[1].stripe_account_id).toBe('acct_platform');
  });

  it('SS5: no outbox row for line without stripeAccountId', async () => {
    const outboxCreates: any[] = [];
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
      mockPrisma.settlements.create.mockResolvedValueOnce({ id: BigInt(1) });
      mockPrisma.settlement_lines.create.mockResolvedValueOnce({ id: BigInt(1) });
      mockPrisma.ledger_entries.create.mockResolvedValue(undefined);
      mockPrisma.transfer_outbox.create.mockImplementation(async (args: any) => {
        outboxCreates.push(args.data);
      });
      return fn(mockPrisma);
    });

    await svc.settle({
      grossCents: 4500, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_test4', stripeEvent: 'evt_4',
      recipients: [FUND_LINE],
    });

    expect(outboxCreates).toHaveLength(0);
  });

  it('SS6: conservation — amounts written to DB sum to grossCents', async () => {
    const lineAmounts: number[] = [];
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
      mockPrisma.settlements.create.mockResolvedValueOnce({ id: BigInt(1) });
      mockPrisma.settlement_lines.create.mockImplementation(async (args: any) => {
        lineAmounts.push(args.data.amount_cents);
        return { id: BigInt(lineAmounts.length) };
      });
      mockPrisma.ledger_entries.create.mockResolvedValue(undefined);
      mockPrisma.transfer_outbox.create.mockResolvedValue(undefined);
      return fn(mockPrisma);
    });

    await svc.settle({
      grossCents: 10000, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_test5', stripeEvent: 'evt_5',
      recipients: [ARTIST_LINE, FUND_LINE, OPS_LINE],
    });

    expect(lineAmounts.reduce((a, b) => a + b, 0)).toBe(10000);
  });

  it('SS7: preview — returns split without writing', () => {
    const preview = svc.preview(10000, 'social_pilot_45_45_10');
    expect(preview.artist_cents).toBe(4500);
    expect(preview.fund_cents).toBe(4500);
    expect(preview.ops_cents).toBe(1000);
    expect(preview.artist_cents + preview.fund_cents + preview.ops_cents).toBe(10000);
    expect(mockPrisma.settlements.create).not.toHaveBeenCalled();
  });

  it('SS8: preview — unknown profileKey throws', () => {
    expect(() => svc.preview(10000, 'standard_90_10')).toThrow();
  });

  it('SS9: getSettlement — returns settlement with lines', async () => {
    mockPrisma.settlements.findUnique.mockResolvedValueOnce(STUB_SETTLEMENT);
    const result = await svc.getSettlement(1);
    expect(result.id).toEqual(BigInt(1));
  });

  it('SS10: getSettlement — not found throws NotFoundException', async () => {
    mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getSettlement(999)).rejects.toThrow(NotFoundException);
  });

  it('SS11: listSettlements — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_SETTLEMENT], 1]);
    const result = await svc.listSettlements(1, 20);
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('SS13: getSettlementLines — found: returns lines', async () => {
    const line = { id: BigInt(1), settlement_id: BigInt(1), recipient_role: 'artist', amount_cents: BigInt(4500) };
    mockPrisma.settlement_lines.findMany.mockResolvedValueOnce([line]);
    const result = await svc.getSettlementLines(1);
    expect(result).toHaveLength(1);
    const arg = mockPrisma.settlement_lines.findMany.mock.calls[0][0] as any;
    expect(arg.where.settlement_id).toEqual(BigInt(1));
  });

  it('SS14: getSettlementLines — empty + not found: throws NotFoundException', async () => {
    mockPrisma.settlement_lines.findMany.mockResolvedValueOnce([]);
    mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getSettlementLines(999)).rejects.toThrow(NotFoundException);
  });

  it('SS15: getLedgerEntries — queries ledger_entries by settlement_id', async () => {
    const entry = { id: BigInt(1), settlement_id: BigInt(1), amount_cents: BigInt(4500), direction: 'credit' };
    mockPrisma.ledger_entries.findMany.mockResolvedValueOnce([entry]);
    const result = await svc.getLedgerEntries(1);
    expect(result).toHaveLength(1);
    const arg = mockPrisma.ledger_entries.findMany.mock.calls[0][0] as any;
    expect(arg.where.settlement_id).toEqual(BigInt(1));
  });

  it('SS16: getTransferOutbox — returns outbox rows for settlement lines', async () => {
    mockPrisma.settlement_lines.findMany.mockResolvedValueOnce([{ id: BigInt(1) }, { id: BigInt(2) }]);
    const outbox = [{ id: BigInt(1), settlement_line_id: BigInt(1), status: 'pending' }];
    mockPrisma.transfer_outbox.findMany.mockResolvedValueOnce(outbox);
    const result = await svc.getTransferOutbox(1);
    expect(result).toHaveLength(1);
    const arg = mockPrisma.transfer_outbox.findMany.mock.calls[0][0] as any;
    expect(arg.where.settlement_line_id.in).toEqual([BigInt(1), BigInt(2)]);
  });

  it('SS12: P2002 unique violation handled idempotently', async () => {
    const p2002 = Object.assign(new Error('Unique'), { code: 'P2002', meta: { target: ['stripe_payment_intent_id'] } });
    mockPrisma.$transaction.mockRejectedValueOnce(p2002);
    mockPrisma.settlements.findUnique.mockResolvedValueOnce({ id: BigInt(5) });

    const id = await svc.settle({
      grossCents: 10000, profileKey: 'social_pilot_45_45_10',
      paymentIntent: 'pi_race', stripeEvent: 'evt_race',
      recipients: [ARTIST_LINE],
    });
    expect(id).toBe(5);
  });
});
