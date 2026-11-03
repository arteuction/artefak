/**
 * Phase 0.3V — SellNowService unit tests
 *
 * SN1  createOffer — valid price: delegates to prisma.create
 * SN2  createOffer — zero price: throws BadRequestException
 * SN3  createOffer — lot not found/active: throws NotFoundException
 * SN4  listUserOffers — returns paged result
 * SN5  counterOffer — found: sets status to countered
 * SN6  counterOffer — not found: throws NotFoundException
 * SN7  acceptOffer — found: sets status to accepted
 * SN8  rejectOffer — found: sets status to rejected
 * SN9  getOffer — found: returns offer
 * SN10 getOffer — not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, BadRequestException } from '@nestjs/common';
import { SellNowService } from './sell-now.service';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

const STUB_LOT = {
  id: BigInt(1), status: 'active', currency: 'BGN',
};

const STUB_OFFER = {
  id: BigInt(1), art_lot_id: BigInt(1), buyer_id: BigInt(10),
  offered_price_cents: BigInt(5000), counter_price_cents: null,
  agreed_price_cents: null, currency: 'BGN', status: 'pending',
  notes: null, created_at: new Date(), updated_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  art_lots: { findFirst: fn() },
  sell_now_offers: { findMany: fn(), findFirst: fn(), create: fn(), update: fn(), count: fn() },
  $transaction: fn(),
};

const mockStripe = { enabled: false, createCheckoutSession: jest.fn() as any };

describe('Phase 0.3V — SellNowService', () => {
  let svc: SellNowService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        SellNowService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: StripeService, useValue: mockStripe },
      ],
    }).compile();
    svc = module.get(SellNowService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('SN1: createOffer — valid: delegates to prisma.create', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    mockPrisma.sell_now_offers.create.mockResolvedValueOnce(STUB_OFFER);
    const result = await svc.createOffer(1, 10, { offered_price_cents: 5000 });
    expect(result.status).toBe('pending');
    expect(mockPrisma.sell_now_offers.create).toHaveBeenCalled();
  });

  it('SN2: createOffer — zero price: throws BadRequestException', async () => {
    await expect(svc.createOffer(1, 10, { offered_price_cents: 0 })).rejects.toThrow(BadRequestException);
  });

  it('SN3: createOffer — lot not found: throws NotFoundException', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(null);
    await expect(svc.createOffer(999, 10, { offered_price_cents: 1000 })).rejects.toThrow(NotFoundException);
  });

  it('SN4: listUserOffers — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_OFFER], 1]);
    const result = await svc.listUserOffers(10);
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('SN5: counterOffer — found: sets status countered', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(STUB_OFFER);
    mockPrisma.sell_now_offers.update.mockResolvedValueOnce({ ...STUB_OFFER, status: 'countered', counter_price_cents: BigInt(4500) });
    const result = await svc.counterOffer(1, 1, 4500);
    expect(result.status).toBe('countered');
  });

  it('SN6: counterOffer — not found: throws NotFoundException', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(null);
    await expect(svc.counterOffer(999, 1, 4500)).rejects.toThrow(NotFoundException);
  });

  it('SN7: acceptOffer — found: sets status accepted', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(STUB_OFFER);
    mockPrisma.sell_now_offers.update.mockResolvedValueOnce({ ...STUB_OFFER, status: 'accepted', agreed_price_cents: BigInt(5000) });
    const result = await svc.acceptOffer(1, 1);
    expect(result.status).toBe('accepted');
  });

  it('SN8: rejectOffer — found: sets status rejected', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(STUB_OFFER);
    mockPrisma.sell_now_offers.update.mockResolvedValueOnce({ ...STUB_OFFER, status: 'rejected' });
    const result = await svc.rejectOffer(1, 1);
    expect(result.status).toBe('rejected');
  });

  it('SN9: getOffer — found: returns offer', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(STUB_OFFER);
    const result = await svc.getOffer(1, 10);
    expect(result.id).toEqual(BigInt(1));
  });

  it('SN10: getOffer — not found: throws NotFoundException', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getOffer(999, 10)).rejects.toThrow(NotFoundException);
  });

  it('SN11: checkoutSession — accepted offer: returns checkout_url', async () => {
    const acceptedOffer = {
      ...STUB_OFFER, status: 'accepted', agreed_price_cents: BigInt(5000),
      art_lots: { artworks: { title: 'Test Artwork' }, currency: 'BGN' },
    };
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(acceptedOffer);
    mockStripe.createCheckoutSession.mockResolvedValueOnce({
      checkout_url: 'https://stripe.com/checkout/test123',
      session_id: 'cs_test123',
    });
    const result = await svc.checkoutSession(1, 10);
    expect(result.checkout_url).toMatch(/stripe/);
    expect(mockStripe.createCheckoutSession).toHaveBeenCalledWith(
      expect.objectContaining({ clientReferenceId: 'sell_now_offer_1' })
    );
  });

  it('SN12: checkoutSession — offer not found: throws NotFoundException', async () => {
    mockPrisma.sell_now_offers.findFirst.mockResolvedValueOnce(null);
    await expect(svc.checkoutSession(999, 10)).rejects.toThrow(NotFoundException);
  });
});
