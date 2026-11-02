/**
 * Phase 0.3V — ArtLotService unit tests
 *
 * AL1  listArtLots default pagination
 * AL2  listArtLots with status filter
 * AL3  getArtLot by numeric id — found
 * AL4  getArtLot by numeric id — not found: throws NotFoundException
 * AL5  getArtLot by slug — found
 * AL6  listBids — delegates after getArtLot
 * AL7  createArtLot — returns draft
 * AL8  updateArtLot — found: returns updated dto
 * AL9  purchaseNow — found: returns purchased status
 * AL10 transition — found: returns transitioned status
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ArtLotService } from './art-lot.service';
import { PrismaService } from '../prisma.service';

const STUB_LOT = {
  id: BigInt(1), slug: 'test-lot', sale_mode: 'auction', status: 'active',
  starting_bid_cents: BigInt(10000), buy_now_price_cents: null,
  currency: 'BGN', estimate_low_cents: null, estimate_high_cents: null,
  published_at: null, closed_at: null, created_at: new Date(),
  artwork_id: BigInt(1), gallery_id: null,
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  art_lots: { findMany: fn(), findFirst: fn(), count: fn() },
  bid: { findMany: fn() },
  sell_now_offers: { findMany: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — ArtLotService', () => {
  let svc: ArtLotService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ArtLotService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ArtLotService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('AL1: listArtLots default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_LOT], 1]);
    const result = await svc.listArtLots();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
    expect(result.meta.total).toBe(1);
  });

  it('AL2: listArtLots with status filter', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listArtLots({ status: 'draft' });
    expect(result.data).toHaveLength(0);
  });

  it('AL3: getArtLot by numeric id — found', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    const result = await svc.getArtLot('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('AL4: getArtLot by numeric id — not found: throws NotFoundException', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getArtLot('999')).rejects.toThrow(NotFoundException);
  });

  it('AL5: getArtLot by slug — found', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    const result = await svc.getArtLot('test-lot');
    expect((result as any).slug).toBe('test-lot');
  });

  it('AL6: listBids — returns bids for lot', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    mockPrisma.bid.findMany.mockResolvedValueOnce([]);
    const result = await svc.listBids('1');
    expect(Array.isArray(result)).toBe(true);
  });

  it('AL7: createArtLot — returns draft with id', async () => {
    const result = await svc.createArtLot(1, 'artist', { title: 'Test' });
    expect(result.status).toBe('draft');
    expect(result).toHaveProperty('id');
  });

  it('AL8: updateArtLot — found: returns updated dto', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    const result = await svc.updateArtLot('1', 1, 'admin', { title: 'Updated' });
    expect(result).toHaveProperty('updated_at');
  });

  it('AL9: purchaseNow — found: returns purchased status', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    const result = await svc.purchaseNow('1', 10);
    expect(result.status).toBe('purchased');
  });

  it('AL10: transition — found: returns transitioned status', async () => {
    mockPrisma.art_lots.findFirst.mockResolvedValueOnce(STUB_LOT);
    const result = await svc.transition('1', 'closed', 1, 'admin');
    expect(result.status).toBe('closed');
  });
});
