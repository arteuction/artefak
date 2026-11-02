/**
 * Phase 0.3V — ReserveService unit tests
 *
 * RS1  getReserve — found: returns reserve
 * RS2  getReserve — not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ReserveService } from './reserve.service';
import { PrismaService } from '../prisma.service';

const STUB = { id: BigInt(1), art_lot_id: BigInt(1), reserve_price_cents: BigInt(10000), currency: 'BGN', created_at: new Date() };

const fn = () => jest.fn() as any;
const mockPrisma = { reserves: { findFirst: fn() } };

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
});
