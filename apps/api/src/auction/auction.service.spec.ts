/**
 * Phase 0.3V — AuctionService unit tests
 *
 * AU1  listAuctions default pagination
 * AU2  listAuctions with status filter
 * AU3  getAuction by numeric id — found
 * AU4  getAuction by slug — found
 * AU5  getAuction — not found: throws NotFoundException
 * AU6  health — db connected: returns status ok
 * AU7  getAuctionItem — found
 * AU8  getAuctionItem — item not found: throws NotFoundException
 * AU9  listBidsForItem — returns bids
 * AU10 getBidHistory — returns history
 * AU11 shipItem — updates fulfillment_status shipped
 * AU12 confirmDelivery — updates fulfillment_status delivered
 * AU13 health — db error: returns status degraded
 * AU14 reAuthorizeItem — found: returns payment_re_authorized true
 * AU15 reAuthorizeItem — not found: throws NotFoundException
 * AU16 createCheckoutSession — item not found: throws NotFoundException
 * AU17 createCheckoutSession — item not 'won': throws ForbiddenException
 * AU18 createCheckoutSession — winner initiates: returns checkout_url
 * AU19 listBidsForItem — item not found: throws NotFoundException
 * AU20 getBidHistory — item not found: throws NotFoundException
 * AU21 shipItem — item not found: throws NotFoundException
 * AU22 confirmDelivery — item not found: throws NotFoundException
 * AU23 createCheckoutSession — wrong user (not winner): throws ForbiddenException
 * AU24 createCheckoutSession — winning bid missing: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { AuctionService } from './auction.service';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

const STUB_AUCTION = {
  id: 1, title: 'Spring Auction', slug: 'spring-auction', status: 'active',
  starts_at: new Date(), ends_at: new Date(), created_at: new Date(),
  ruleset_id: null,
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  auction: { findMany: fn(), findUnique: fn(), findFirst: fn(), count: fn() },
  auctionItem: { findFirst: fn(), update: fn() },
  bid: { findMany: fn() },
  $transaction: fn(),
  $queryRaw: fn(),
};

const mockStripe = { createCheckoutSession: jest.fn() as any };

describe('Phase 0.3V — AuctionService', () => {
  let svc: AuctionService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        AuctionService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: StripeService, useValue: mockStripe },
      ],
    }).compile();
    svc = module.get(AuctionService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('AU1: listAuctions default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_AUCTION], 1]);
    const result = await svc.listAuctions();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('AU2: listAuctions with status filter', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listAuctions({ status: 'closed' });
    expect(result.meta.total).toBe(0);
  });

  it('AU3: getAuction by numeric id — found', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(STUB_AUCTION);
    const result = await svc.getAuction('1');
    expect(result.id).toBe(1);
  });

  it('AU4: getAuction by slug — found', async () => {
    // non-numeric: byId=null (no DB call), findFirst by slug
    mockPrisma.auction.findFirst.mockResolvedValueOnce(STUB_AUCTION);
    const result = await svc.getAuction('spring-auction');
    expect(result.slug).toBe('spring-auction');
  });

  it('AU5: getAuction — not found: throws NotFoundException', async () => {
    mockPrisma.auction.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getAuction('missing')).rejects.toThrow(NotFoundException);
  });

  it('AU6: health — db connected: returns status ok', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ '1': 1 }]);
    const result = await svc.health();
    expect(result.status).toBe('ok');
    expect(result.db).toBe('connected');
  });

  it('AU7: getAuctionItem — found', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(STUB_AUCTION);
    const item = { id: 1, auction_id: 1, art_lots: null };
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(item);
    const result = await svc.getAuctionItem('1', 1);
    expect(result).toBe(item);
  });

  it('AU8: getAuctionItem — item not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(STUB_AUCTION);
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getAuctionItem('1', 99)).rejects.toThrow(NotFoundException);
  });

  it('AU9: listBidsForItem — returns bids', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(STUB_AUCTION);
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1), auction_id: 1 });
    mockPrisma.bid.findMany.mockResolvedValueOnce([{ id: BigInt(1), amount_cents: BigInt(5000) }]);
    const result = await svc.listBidsForItem('1', 1);
    expect(result).toHaveLength(1);
  });

  it('AU10: getBidHistory — returns history', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.bid.findMany.mockResolvedValueOnce([{ id: BigInt(1) }, { id: BigInt(2) }]);
    const result = await svc.getBidHistory(1);
    expect(result).toHaveLength(2);
  });

  it('AU11: shipItem — updates fulfillment_status shipped', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.auctionItem.update.mockResolvedValueOnce({ id: BigInt(1), fulfillment_status: 'shipped' });
    const result = await svc.shipItem(1);
    expect(result.fulfillment_status).toBe('shipped');
  });

  it('AU12: confirmDelivery — updates fulfillment_status delivered', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.auctionItem.update.mockResolvedValueOnce({ id: BigInt(1), fulfillment_status: 'delivered' });
    const result = await svc.confirmDelivery(1);
    expect(result.fulfillment_status).toBe('delivered');
  });

  it('AU13: health — db error: returns status degraded', async () => {
    mockPrisma.$queryRaw.mockRejectedValueOnce(new Error('Connection refused'));
    const result = await svc.health();
    expect(result.status).toBe('degraded');
    expect(result.db).toBe('disconnected');
  });

  it('AU14: reAuthorizeItem — found: returns payment_re_authorized true', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1), status: 'sold' });
    const result = await svc.reAuthorizeItem(1);
    expect(result.payment_re_authorized).toBe(true);
    expect(result.id).toBe(1);
  });

  it('AU15: reAuthorizeItem — not found: throws NotFoundException', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.reAuthorizeItem(999)).rejects.toThrow(NotFoundException);
  });

  it('AU16: createCheckoutSession — item not found: throws NotFoundException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([]);
    await expect(svc.createCheckoutSession(999, 1, { success_url: 's', cancel_url: 'c' }))
      .rejects.toThrow(NotFoundException);
  });

  it('AU17: createCheckoutSession — item not won: throws ForbiddenException', async () => {
    const { ForbiddenException } = await import('@nestjs/common');
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      item_id: 1, item_status: 'open', winner_user_id: 1, winning_bid_id: 1, bid_amount: 10000, artwork_title: 'Test',
    }]);
    await expect(svc.createCheckoutSession(1, 1, { success_url: 's', cancel_url: 'c' }))
      .rejects.toThrow(ForbiddenException);
  });

  it('AU18: createCheckoutSession — winner initiates: returns checkout_url', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      item_id: 1, item_status: 'won', winner_user_id: 1, winning_bid_id: 1, bid_amount: 25000, artwork_title: 'Blue Sky',
    }]);
    mockStripe.createCheckoutSession.mockResolvedValueOnce({ checkout_url: 'https://checkout.stripe.com/test', session_id: 'cs_test' });
    const result = await svc.createCheckoutSession(1, 1, { success_url: 'https://success', cancel_url: 'https://cancel' });
    expect(result.checkout_url).toBe('https://checkout.stripe.com/test');
    expect(result.amount_cents).toBe(25000);
    expect(result.currency).toBe('eur');
  });

  it('AU19: listBidsForItem — item not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(STUB_AUCTION);
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.listBidsForItem('1', 999)).rejects.toThrow(NotFoundException);
  });

  it('AU20: getBidHistory — item not found: throws NotFoundException', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getBidHistory(999)).rejects.toThrow(NotFoundException);
  });

  it('AU21: shipItem — item not found: throws NotFoundException', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.shipItem(999)).rejects.toThrow(NotFoundException);
  });

  it('AU22: confirmDelivery — item not found: throws NotFoundException', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.confirmDelivery(999)).rejects.toThrow(NotFoundException);
  });

  it('AU23: createCheckoutSession — wrong user (not winner): throws ForbiddenException', async () => {
    const { ForbiddenException } = await import('@nestjs/common');
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      item_id: 1, item_status: 'won', winner_user_id: 5, winning_bid_id: 1, bid_amount: 25000, artwork_title: 'Test',
    }]);
    await expect(svc.createCheckoutSession(1, 99, { success_url: 's', cancel_url: 'c' }))
      .rejects.toThrow(ForbiddenException);
  });

  it('AU24: createCheckoutSession — winning bid missing: throws NotFoundException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      item_id: 1, item_status: 'won', winner_user_id: 1, winning_bid_id: null, bid_amount: null, artwork_title: 'Test',
    }]);
    await expect(svc.createCheckoutSession(1, 1, { success_url: 's', cancel_url: 'c' }))
      .rejects.toThrow(NotFoundException);
  });
});
