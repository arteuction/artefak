/**
 * Phase 0.5 — SchedulerService
 *
 * SC1  closeExpiredLots — no-ops when no expired items
 * SC2  closeExpiredLots — closes item with winning bid (sold + won/outbid)
 * SC3  closeExpiredLots — closes item without bids (unsold)
 * SC4  closeExpiredLots — skips item already not open (idempotent)
 * SC5  closeExpiredLots — continues after per-item error
 * SC6  expireBidAuthorizations — no-ops when none expired
 * SC7  expireBidAuthorizations — marks expired bids authorization_expired
 * SC8  expireBidAuthorizations — logs warning for won bid
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { SchedulerService } from './scheduler.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const mockTx = {
  auctionItem: { findUnique: fn(), update: fn() },
  bid:         { findFirst: fn(), update: fn(), updateMany: fn() },
};

const mockPrisma = {
  $queryRaw:  fn(),
  $transaction: fn(),
  bid:          { findMany: fn(), update: fn() },
  auctionItem:  { update: fn() },
};

describe('Phase 0.5 — SchedulerService', () => {
  let svc: SchedulerService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        SchedulerService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(SchedulerService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  // ── closeExpiredLots ────────────────────────────────────────────────────────

  it('SC1: closeExpiredLots — no-ops when no expired items', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([]);
    await svc.closeExpiredLots();
    expect(mockPrisma.$transaction).not.toHaveBeenCalled();
  });

  it('SC2: closeExpiredLots — closes item with winning bid', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ id: BigInt(1) }]);
    mockPrisma.$transaction.mockImplementationOnce(async (cb: any) => cb(mockTx));
    mockTx.auctionItem.findUnique.mockResolvedValueOnce({ id: BigInt(1), status: 'open' });
    mockTx.bid.findFirst.mockResolvedValueOnce({
      id: BigInt(10), user_id: BigInt(5), amount_cents: BigInt(5000),
    });
    mockTx.bid.update.mockResolvedValueOnce(undefined);
    mockTx.bid.updateMany.mockResolvedValueOnce(undefined);
    mockTx.auctionItem.update.mockResolvedValueOnce(undefined);

    await svc.closeExpiredLots();

    expect(mockTx.bid.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'won' }) })
    );
    expect(mockTx.bid.updateMany).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'outbid' }) })
    );
    expect(mockTx.auctionItem.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'sold' }) })
    );
  });

  it('SC3: closeExpiredLots — closes item without bids (unsold)', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ id: BigInt(2) }]);
    mockPrisma.$transaction.mockImplementationOnce(async (cb: any) => cb(mockTx));
    mockTx.auctionItem.findUnique.mockResolvedValueOnce({ id: BigInt(2), status: 'open' });
    mockTx.bid.findFirst.mockResolvedValueOnce(null);
    mockTx.auctionItem.update.mockResolvedValueOnce(undefined);

    await svc.closeExpiredLots();

    expect(mockTx.auctionItem.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'unsold' }) })
    );
  });

  it('SC4: closeExpiredLots — skips item already not open', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ id: BigInt(3) }]);
    mockPrisma.$transaction.mockImplementationOnce(async (cb: any) => cb(mockTx));
    mockTx.auctionItem.findUnique.mockResolvedValueOnce({ id: BigInt(3), status: 'sold' });

    await svc.closeExpiredLots();

    expect(mockTx.bid.findFirst).not.toHaveBeenCalled();
  });

  it('SC5: closeExpiredLots — continues after per-item error', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ id: BigInt(4) }, { id: BigInt(5) }]);
    mockPrisma.$transaction
      .mockRejectedValueOnce(new Error('DB timeout'))
      .mockImplementationOnce(async (cb: any) => cb(mockTx));
    mockTx.auctionItem.findUnique.mockResolvedValueOnce({ id: BigInt(5), status: 'open' });
    mockTx.bid.findFirst.mockResolvedValueOnce(null);
    mockTx.auctionItem.update.mockResolvedValueOnce(undefined);

    await expect(svc.closeExpiredLots()).resolves.not.toThrow();
    expect(mockPrisma.$transaction).toHaveBeenCalledTimes(2);
  });

  // ── expireBidAuthorizations ─────────────────────────────────────────────────

  it('SC6: expireBidAuthorizations — no-ops when none expired', async () => {
    mockPrisma.bid.findMany.mockResolvedValueOnce([]);
    await svc.expireBidAuthorizations();
    expect(mockPrisma.bid.update).not.toHaveBeenCalled();
  });

  it('SC7: expireBidAuthorizations — marks bids authorization_expired', async () => {
    mockPrisma.bid.findMany.mockResolvedValueOnce([
      { id: BigInt(20), status: 'accepted', auction_item_id: BigInt(1), amount_cents: BigInt(3000), user_id: BigInt(2) },
    ]);
    mockPrisma.bid.update.mockResolvedValueOnce(undefined);

    await svc.expireBidAuthorizations();

    expect(mockPrisma.bid.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ payment_status: 'authorization_expired' }) })
    );
  });

  it('SC8: expireBidAuthorizations — logs warning for won bid', async () => {
    const warnSpy = jest.spyOn((svc as any).logger, 'warn').mockImplementation(() => {});
    mockPrisma.bid.findMany.mockResolvedValueOnce([
      { id: BigInt(21), status: 'won', auction_item_id: BigInt(2), amount_cents: BigInt(9900), user_id: BigInt(3) },
    ]);
    mockPrisma.bid.update.mockResolvedValueOnce(undefined);

    await svc.expireBidAuthorizations();

    expect(warnSpy).toHaveBeenCalledWith(expect.stringContaining('authorization expired'));
  });
});
