/**
 * Phase 0.3V — ReserveService unit tests
 *
 * RS1  getReserve — found: returns reserve
 * RS2  getReserve — not found: throws NotFoundException
 * RS3  listReserves — no filter: returns all reserves
 * RS4  listReserves — with artLotId: filters by art_lot_id
 * RS5  createReserve — writes to reserves table
 * RS6  updateReserve — found: persists new price
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ReserveService } from './reserve.service';
import { PrismaService } from '../prisma.service';

const STUB = { id: BigInt(1), auction_item_id: BigInt(1), reserve_price_cents: BigInt(10000), highest_bid_cents: BigInt(0), status: 'not_reached', created_at: new Date() };

const fn = () => jest.fn() as any;
const mockPrisma = { reserves: { findFirst: fn(), findMany: fn(), create: fn(), update: fn() } };

describe('Phase 0.3V — ReserveService', () => {
  let svc: ReserveService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ReserveService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ReserveService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('RS1: getReserve — found: returns reserve', async () => {
    mockPrisma.reserves.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getReserve(1);
    expect(result.id).toEqual(BigInt(1));
  });

  it('RS2: getReserve — not found: throws NotFoundException', async () => {
    mockPrisma.reserves.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getReserve(999)).rejects.toThrow(NotFoundException);
  });

  it('RS3: listReserves — no filter: queries all', async () => {
    mockPrisma.reserves.findMany.mockResolvedValueOnce([STUB]);
    const result = await svc.listReserves();
    expect(mockPrisma.reserves.findMany).toHaveBeenCalledWith(expect.objectContaining({ where: undefined }));
    expect(result).toHaveLength(1);
  });

  it('RS4: listReserves — auctionItemId filter applied', async () => {
    mockPrisma.reserves.findMany.mockResolvedValueOnce([STUB]);
    await svc.listReserves(1);
    const call = mockPrisma.reserves.findMany.mock.calls[0][0] as any;
    expect(call.where.auction_item_id).toEqual(BigInt(1));
  });

  it('RS5: createReserve — writes to reserves table', async () => {
    mockPrisma.reserves.create.mockResolvedValueOnce(STUB);
    const result = await svc.createReserve({ auction_item_id: 1, reserve_price_cents: 10000 });
    const arg = mockPrisma.reserves.create.mock.calls[0][0] as any;
    expect(arg.data.reserve_price_cents).toEqual(BigInt(10000));
    expect(arg.data.auction_item_id).toEqual(BigInt(1));
    expect(result.id).toEqual(BigInt(1));
  });

  it('RS6: updateReserve — found: persists counter_offer_cents', async () => {
    mockPrisma.reserves.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.reserves.update.mockResolvedValueOnce({ ...STUB, counter_offer_cents: BigInt(9000) });
    const result = await svc.updateReserve(1, { counter_offer_cents: 9000 });
    expect(mockPrisma.reserves.update).toHaveBeenCalled();
    expect(result.counter_offer_cents).toEqual(BigInt(9000));
  });
});
