/**
 * Phase 0.3V — CreateSettlement unit tests
 *
 * CS1  execute — creates settlement + lines + ledger_entries + transfer_outbox
 * CS2  execute — idempotency: duplicate payment_intent returns existing id
 * CS3  execute — idempotency guard inside transaction (existing found early)
 * CS4  execute — recipient without stripeAccountId skips transfer_outbox
 * CS5  execute — multiple recipients: one with Stripe, one without
 * CS6  execute — asserts conservation before writing (throws on violation)
 * CS7  execute — rethrows non-idempotency Prisma errors
 * CS8  execute — auctionId linked in settlements.create
 * CS9  execute — bookPurchaseId linked when present
 * CS10 execute — recipient type 'fund' written correctly
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { CreateSettlement } from './create-settlement';
import { PrismaService } from '../prisma.service';
import { Money } from './money';
import { SettlementResult } from './settlement-result';
import { SplitProfile } from './split-profile';
import { SettlementCalculator } from './settlement-calculator';
import type { RecipientLine } from './recipient-line';

// ── helpers ───────────────────────────────────────────────────────────────────

function makeResult(grossCents = 10000): SettlementResult {
  const profile = SplitProfile.fromKey('social_pilot_45_45_10');
  const gross = Money.fromCents(grossCents, 'EUR');
  return new SettlementCalculator().calculate(gross, profile);
}

function artistLine(amount: Money, stripeAccountId: string | null = 'acct_test_artist'): RecipientLine {
  return { type: 'artist', legalEntityId: null, userId: 10, entityName: 'Test Artist', entityEik: null, entityRole: 'artist', stripeAccountId, amount, weight: 4500 };
}

function fundLine(amount: Money): RecipientLine {
  return { type: 'fund', legalEntityId: 5, userId: null, entityName: 'Test NGO', entityEik: '123456789', entityRole: 'ngo', stripeAccountId: null, amount, weight: 4500 };
}

// ── Prisma mock factory ───────────────────────────────────────────────────────

function makePrismaMock(overrides: Record<string, unknown> = {}) {
  const txMock = {
    settlements: {
      findUnique: jest.fn(async () => null) as any,
      create: jest.fn(async () => ({ id: BigInt(1) })) as any,
    },
    settlement_lines: {
      create: jest.fn(async () => ({ id: BigInt(10) })) as any,
    },
    ledger_entries: {
      create: jest.fn(async () => ({})) as any,
    },
    transfer_outbox: {
      create: jest.fn(async () => ({})) as any,
    },
    ...overrides,
  };

  const mock = {
    settlements: {
      findUnique: jest.fn(async () => null) as any,
    },
    $transaction: jest.fn(async (fn: (tx: typeof txMock) => Promise<number>) => fn(txMock)) as any,
    _tx: txMock,
  };

  return mock;
}

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3V — CreateSettlement', () => {
  let service: CreateSettlement;
  let prismaMock: ReturnType<typeof makePrismaMock>;

  beforeEach(async () => {
    prismaMock = makePrismaMock();
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        CreateSettlement,
        { provide: PrismaService, useValue: prismaMock },
      ],
    }).compile();
    service = module.get(CreateSettlement);
  });

  it('CS1: execute — creates settlement, lines, ledger_entries, transfer_outbox', async () => {
    const result = makeResult(10000);
    const artist = artistLine(result.artist, 'acct_test_artist');
    const id = await service.execute(result, 'pi_test_1', 'evt_1', [artist], 42);

    expect(id).toBe(1);
    const tx = prismaMock._tx;
    expect(tx.settlements.create).toHaveBeenCalledTimes(1);
    expect(tx.settlement_lines.create).toHaveBeenCalledTimes(1);
    expect(tx.ledger_entries.create).toHaveBeenCalledTimes(1);
    expect(tx.transfer_outbox.create).toHaveBeenCalledTimes(1);
  });

  it('CS2: execute — idempotency: outer findUnique returns existing id without DB writes', async () => {
    // The P2002 path: settlements.create throws, outer catch finds existing
    prismaMock._tx.settlements.findUnique = jest.fn(async () => null) as any;
    prismaMock._tx.settlements.create = jest.fn(async () => { throw Object.assign(new Error('Unique'), { code: 'P2002', meta: { target: ['stripe_payment_intent_id'] } }); }) as any;
    prismaMock.settlements.findUnique = jest.fn(async () => ({ id: BigInt(99) })) as any;

    const result = makeResult(10000);
    const id = await service.execute(result, 'pi_dupe', 'evt_dupe', [artistLine(result.artist)]);
    expect(id).toBe(99);
    // No settlement_lines or ledger_entries should be written
    expect(prismaMock._tx.settlement_lines.create).not.toHaveBeenCalled();
  });

  it('CS3: execute — idempotency guard inside transaction returns early', async () => {
    // findUnique inside tx returns existing → early return, no settlements.create
    prismaMock._tx.settlements.findUnique = jest.fn(async () => ({ id: BigInt(55) })) as any;

    const result = makeResult(10000);
    const id = await service.execute(result, 'pi_existing', 'evt_existing', [artistLine(result.artist)]);
    expect(id).toBe(55);
    expect(prismaMock._tx.settlements.create).not.toHaveBeenCalled();
  });

  it('CS4: execute — recipient without stripeAccountId skips transfer_outbox', async () => {
    const result = makeResult(10000);
    const noStripe = artistLine(result.artist, null);  // no Stripe account
    await service.execute(result, 'pi_no_stripe', 'evt_no_stripe', [noStripe]);

    const tx = prismaMock._tx;
    expect(tx.settlement_lines.create).toHaveBeenCalledTimes(1);
    expect(tx.ledger_entries.create).toHaveBeenCalledTimes(1);
    expect(tx.transfer_outbox.create).not.toHaveBeenCalled();
  });

  it('CS5: execute — multiple recipients: one with Stripe, one without', async () => {
    // Use a result with enough to split manually
    const gross = Money.fromCents(10000, 'EUR');
    const artistAmt = Money.fromCents(4500, 'EUR');
    const fundAmt   = Money.fromCents(4500, 'EUR');
    const opsAmt    = Money.fromCents(1000, 'EUR');
    const result = new SettlementResult(gross, artistAmt, fundAmt, opsAmt, 'social_pilot_45_45_10', 1, 4500, 4500, 1000);

    // settlement_lines.create returns different ids per call
    let lineCallCount = 0;
    prismaMock._tx.settlement_lines.create = jest.fn(async () => ({ id: BigInt(++lineCallCount + 100) })) as any;

    await service.execute(result, 'pi_multi', 'evt_multi', [
      artistLine(artistAmt, 'acct_artist'),
      fundLine(fundAmt),
    ]);

    const tx = prismaMock._tx;
    expect(tx.settlement_lines.create).toHaveBeenCalledTimes(2);
    expect(tx.ledger_entries.create).toHaveBeenCalledTimes(2);
    expect(tx.transfer_outbox.create).toHaveBeenCalledTimes(1); // only artist has Stripe
  });

  it('CS6: execute — assertConservation throws before any DB write if violated', async () => {
    // Manually construct a result with wrong conservation
    const gross    = Money.fromCents(10000, 'EUR');
    const artistAmt = Money.fromCents(4000, 'EUR'); // wrong — should be 4500
    const fundAmt   = Money.fromCents(4500, 'EUR');
    const opsAmt    = Money.fromCents(1000, 'EUR');
    const badResult = new SettlementResult(gross, artistAmt, fundAmt, opsAmt, 'social_pilot_45_45_10', 1, 4500, 4500, 1000);

    await expect(service.execute(badResult, 'pi_bad', 'evt_bad', [])).rejects.toThrow('conservation violated');
    expect(prismaMock.$transaction).not.toHaveBeenCalled();
  });

  it('CS7: execute — rethrows non-idempotency Prisma errors', async () => {
    prismaMock._tx.settlements.create = jest.fn(async () => { throw new Error('DB connection lost'); }) as any;

    const result = makeResult(10000);
    await expect(service.execute(result, 'pi_err', 'evt_err', [])).rejects.toThrow('DB connection lost');
  });

  it('CS8: execute — auctionId written as BigInt in settlements.create', async () => {
    const result = makeResult(10000);
    await service.execute(result, 'pi_auc', 'evt_auc', [], 77);

    const createCall = prismaMock._tx.settlements.create.mock.calls[0][0] as { data: Record<string, unknown> };
    expect(createCall.data.auction_id).toBe(BigInt(77));
    expect(createCall.data.book_purchase_id).toBeNull();
  });

  it('CS9: execute — bookPurchaseId written when present, auctionId null', async () => {
    const result = makeResult(10000);
    await service.execute(result, 'pi_book', 'evt_book', [], undefined, 33);

    const createCall = prismaMock._tx.settlements.create.mock.calls[0][0] as { data: Record<string, unknown> };
    expect(createCall.data.book_purchase_id).toBe(BigInt(33));
    expect(createCall.data.auction_id).toBeNull();
  });

  it('CS10: execute — ledger_entries type is always "credit" (three-ledger invariant)', async () => {
    const result = makeResult(10000);
    const artist = artistLine(result.artist, null);
    await service.execute(result, 'pi_ledger', 'evt_ledger', [artist]);

    const ledgerCall = prismaMock._tx.ledger_entries.create.mock.calls[0][0] as { data: Record<string, unknown> };
    expect(ledgerCall.data.type).toBe('credit');
  });
});
