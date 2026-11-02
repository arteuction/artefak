/**
 * Phase 0.3V — DisputeService unit tests
 *
 * DS1  listDisputes — admin sees all
 * DS2  listDisputes — buyer scoped to opened_by
 * DS3  getDispute — found, owner: returns it
 * DS4  getDispute — not found: throws NotFoundException
 * DS5  getDispute — found, different user: throws ForbiddenException
 * DS6  createDispute — delegates to prisma.create
 * DS7  assignDispute — non-admin: throws ForbiddenException
 * DS8  assignDispute — admin: updates assigned_to
 * DS9  resolveDispute — non-admin: throws ForbiddenException
 * DS10 resolveDispute — admin: sets status resolved
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { DisputeService } from './dispute.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), type: 'non_delivery', status: 'open',
  description: 'Item not received', opened_by: BigInt(10),
  art_lot_id: null, auction_item_id: null, sell_now_offer_id: null,
  assigned_to: null, resolved_at: null, created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  disputes: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() },
  dispute_escalations: { create: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — DisputeService', () => {
  let svc: DisputeService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [DisputeService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(DisputeService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('DS1: listDisputes — admin sees all', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listDisputes(1, 'admin');
    expect(result.data).toHaveLength(1);
  });

  it('DS2: listDisputes — buyer scoped to own', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listDisputes(99, 'buyer');
    expect(result.meta.total).toBe(0);
  });

  it('DS3: getDispute — owner: returns it', async () => {
    mockPrisma.disputes.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getDispute(1, 10, 'buyer');
    expect(result.id).toEqual(BigInt(1));
  });

  it('DS4: getDispute — not found: throws NotFoundException', async () => {
    mockPrisma.disputes.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getDispute(999, 1, 'buyer')).rejects.toThrow(NotFoundException);
  });

  it('DS5: getDispute — different user: throws ForbiddenException', async () => {
    mockPrisma.disputes.findFirst.mockResolvedValueOnce(STUB); // opened_by=10
    await expect(svc.getDispute(1, 99, 'buyer')).rejects.toThrow(ForbiddenException);
  });

  it('DS6: createDispute — delegates to prisma.create', async () => {
    mockPrisma.disputes.create.mockResolvedValueOnce(STUB);
    const result = await svc.createDispute(10, { type: 'non_delivery', description: 'Missing' });
    expect(mockPrisma.disputes.create).toHaveBeenCalled();
    expect(result.status).toBe('open');
  });

  it('DS7: assignDispute — non-admin: throws ForbiddenException', async () => {
    await expect(svc.assignDispute(1, 1, 'buyer', 5)).rejects.toThrow(ForbiddenException);
  });

  it('DS8: assignDispute — admin: updates assigned_to', async () => {
    mockPrisma.disputes.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.disputes.update.mockResolvedValueOnce({ ...STUB, assigned_to: BigInt(5) });
    const result = await svc.assignDispute(1, 1, 'admin', 5);
    expect(result.assigned_to).toEqual(BigInt(5));
  });

  it('DS9: resolveDispute — non-admin: throws ForbiddenException', async () => {
    await expect(svc.resolveDispute(1, 1, 'buyer', { resolution: 'Refunded' })).rejects.toThrow(ForbiddenException);
  });

  it('DS10: resolveDispute — admin: sets status resolved', async () => {
    mockPrisma.disputes.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.disputes.update.mockResolvedValueOnce({ ...STUB, status: 'resolved', resolution: 'Refunded' });
    const result = await svc.resolveDispute(1, 1, 'admin', { resolution: 'Refunded' });
    expect(result.status).toBe('resolved');
  });
});
