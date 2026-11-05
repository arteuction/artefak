/**
 * Phase 0.3V — BidConcurrencyService unit tests (no DB)
 *
 * BC1  placeBid — item not found: throws BidRejected
 * BC2  placeBid — item not open: throws BidRejected
 * BC3  placeBid — auction ended: throws BidRejected
 * BC4  placeBid — bid below minimum: throws BidRejected
 * BC5  placeBid — first bid above starting: accepted
 * BC6  placeBid — second bid outbids first and creates new accepted bid
 * BC7  placeBid — deadlock error is converted to BidRejected
 * BC8  placeBid — write conflict error is converted to BidRejected
 * BC9  placeBid — non-serialization DB error rethrows as-is
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { BidConcurrencyService, BidRejected } from './bid-concurrency.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const mockTx = {
  $queryRaw: fn(),
  bid: { updateMany: fn(), create: fn() },
};

const mockPrisma = {
  $transaction: fn(),
};

describe('Phase 0.3V — BidConcurrencyService', () => {
  let svc: BidConcurrencyService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [BidConcurrencyService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(BidConcurrencyService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  const runWithTx = (txFn: (tx: any) => any) => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => fn(mockTx));
    return txFn;
  };

  it('BC1: placeBid — item not found: throws BidRejected', async () => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockTx.$queryRaw.mockResolvedValueOnce([]); // no rows
      return fn(mockTx);
    });
    await expect(svc.placeBid({ auctionItemId: 999, bidderId: 1, amountCents: BigInt(5000) }))
      .rejects.toThrow(BidRejected);
  });

  it('BC2: placeBid — item status not open: throws BidRejected', async () => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockTx.$queryRaw.mockResolvedValueOnce([{
        id: 1, status: 'closed', bid_increment_cents: null,
        auction_ended: 0, starting_bid_cents: BigInt(1000), ruleset_increment: null,
      }]);
      return fn(mockTx);
    });
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(5000) }))
      .rejects.toThrow(BidRejected);
  });

  it('BC3: placeBid — auction ended: throws BidRejected', async () => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockTx.$queryRaw.mockResolvedValueOnce([{
        id: 1, status: 'open', bid_increment_cents: null,
        auction_ended: 1, starting_bid_cents: BigInt(1000), ruleset_increment: null,
      }]);
      return fn(mockTx);
    });
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(5000) }))
      .rejects.toThrow(BidRejected);
  });

  it('BC4: placeBid — below minimum: throws BidRejected', async () => {
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockTx.$queryRaw
        .mockResolvedValueOnce([{
          id: 1, status: 'open', bid_increment_cents: BigInt(500),
          auction_ended: 0, starting_bid_cents: BigInt(1000), ruleset_increment: null,
        }])
        .mockResolvedValueOnce([{ max_amount: BigInt(2000) }]); // current best = 2000, min = 2500
      return fn(mockTx);
    });
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(2100) }))
      .rejects.toThrow(BidRejected);
  });

  it('BC5: placeBid — first bid above starting: accepted', async () => {
    const STUB_BID = { id: BigInt(99), amount_cents: BigInt(1500), status: 'accepted' };
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockTx.$queryRaw
        .mockResolvedValueOnce([{
          id: 1, status: 'open', bid_increment_cents: BigInt(500),
          auction_ended: 0, starting_bid_cents: BigInt(1000), ruleset_increment: null,
        }])
        .mockResolvedValueOnce([{ max_amount: null }]); // no prior bids
      mockTx.bid.create.mockResolvedValueOnce(STUB_BID);
      return fn(mockTx);
    });
    const result = await svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(1500) });
    expect(result.bidId).toEqual(BigInt(99));
    expect(result.status).toBe('accepted');
  });

  it('BC6: placeBid — second bid outbids first and marks previous outbid', async () => {
    const STUB_BID = { id: BigInt(100), amount_cents: BigInt(2500), status: 'accepted' };
    mockPrisma.$transaction.mockImplementationOnce(async (fn: any) => {
      mockTx.$queryRaw
        .mockResolvedValueOnce([{
          id: 1, status: 'open', bid_increment_cents: BigInt(500),
          auction_ended: 0, starting_bid_cents: BigInt(1000), ruleset_increment: null,
        }])
        .mockResolvedValueOnce([{ max_amount: BigInt(2000) }]); // existing best = 2000, min = 2500
      mockTx.bid.updateMany.mockResolvedValueOnce({ count: 1 });
      mockTx.bid.create.mockResolvedValueOnce(STUB_BID);
      return fn(mockTx);
    });
    const result = await svc.placeBid({ auctionItemId: 1, bidderId: 2, amountCents: BigInt(2500) });
    expect(result.bidId).toEqual(BigInt(100));
    expect(mockTx.bid.updateMany).toHaveBeenCalledWith(
      expect.objectContaining({ data: { status: 'outbid' } })
    );
  });

  it('BC7: placeBid — deadlock error converts to BidRejected', async () => {
    mockPrisma.$transaction.mockRejectedValueOnce(new Error('deadlock found when trying to get lock'));
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(5000) }))
      .rejects.toThrow(BidRejected);
  });

  it('BC8: placeBid — write conflict error converts to BidRejected', async () => {
    mockPrisma.$transaction.mockRejectedValueOnce(new Error('write conflict in transaction'));
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(5000) }))
      .rejects.toThrow(BidRejected);
  });

  it('BC9: placeBid — non-serialization error rethrows as-is', async () => {
    const dbErr = new Error('connection reset by peer');
    mockPrisma.$transaction.mockRejectedValueOnce(dbErr);
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(5000) }))
      .rejects.toThrow('connection reset by peer');
    // must NOT be wrapped in BidRejected
    await expect(svc.placeBid({ auctionItemId: 1, bidderId: 1, amountCents: BigInt(5000) }).catch(e => e))
      .resolves.not.toBeInstanceOf(BidRejected);
  });
});
