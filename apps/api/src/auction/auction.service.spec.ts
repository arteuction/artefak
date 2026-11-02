/**
 * Phase 0.3V — AuctionService unit tests
 *
 * AU1  listAuctions default pagination
 * AU2  listAuctions with status filter
 * AU3  getAuction by numeric id — found
 * AU4  getAuction by slug — found
 * AU5  getAuction — not found: throws NotFoundException
 * AU6  health — db connected: returns status ok
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { AuctionService } from './auction.service';
import { PrismaService } from '../prisma.service';

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

describe('Phase 0.3V — AuctionService', () => {
  let svc: AuctionService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [AuctionService, { provide: PrismaService, useValue: mockPrisma }],
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
});
