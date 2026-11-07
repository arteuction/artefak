/**
 * Phase 0.3V — OwnershipTransferService unit tests
 *
 * OT1  listTransfers — admin sees all
 * OT2  listTransfers — buyer scoped to to_user_id
 * OT3  getTransfer — found, recipient: returns it
 * OT4  getTransfer — not found: throws NotFoundException
 * OT5  getTransfer — found, different user: throws ForbiddenException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { OwnershipTransferService } from './ownership-transfer.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), to_user_id: BigInt(10), transfer_price_cents: BigInt(50000),
  currency: 'BGN', channel: 'auction', transferred_at: new Date(), created_at: new Date(),
  art_lot_id: BigInt(1), auction_item_id: null, sell_now_offer_id: null,
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  ownership_transfers: { findMany: fn(), findFirst: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — OwnershipTransferService', () => {
  let svc: OwnershipTransferService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [OwnershipTransferService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(OwnershipTransferService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('OT1: listTransfers — admin sees all', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listTransfers(1, 'admin');
    expect(result.data).toHaveLength(1);
  });

  it('OT2: listTransfers — buyer scoped to own', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listTransfers(99, 'buyer');
    expect(result.meta.total).toBe(0);
  });

  it('OT3: getTransfer — recipient: returns it', async () => {
    mockPrisma.ownership_transfers.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getTransfer(1, 10, 'buyer');
    expect(result.id).toEqual(BigInt(1));
  });

  it('OT4: getTransfer — not found: throws NotFoundException', async () => {
    mockPrisma.ownership_transfers.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getTransfer(999, 1, 'buyer')).rejects.toThrow(NotFoundException);
  });

  it('OT5: getTransfer — different user: throws ForbiddenException', async () => {
    mockPrisma.ownership_transfers.findFirst.mockResolvedValueOnce(STUB); // to_user_id=10
    await expect(svc.getTransfer(1, 99, 'buyer')).rejects.toThrow(ForbiddenException);
  });
});
