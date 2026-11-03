/**
 * Phase 0.6 — AdminService unit tests
 *
 * AS1  listSettlements — returns paged result
 * AS2  getSettlement — not found: throws NotFoundException
 * AS3  listUsers — returns paged result
 * AS4  updateUser — not found: throws NotFoundException
 * AS5  closeAuction — updates status to closed
 * AS6  disburseSettlementLine — not found: throws NotFoundException
 * AS7  disburseSettlementLine — already paid: returns disbursed:false
 * AS8  listLedger — returns paged result
 * AS9  listAuditLog — returns paged result
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { AdminService } from './admin.service';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

const fn = () => jest.fn() as any;

const mockPrisma = {
  settlements: { findMany: fn(), count: fn(), findUnique: fn() },
  ledger_entries: { findMany: fn(), count: fn() },
  refunds: { findMany: fn(), count: fn() },
  user: { findMany: fn(), count: fn(), findUnique: fn(), update: fn() },
  transfer_outbox: { findMany: fn(), count: fn(), findUnique: fn() },
  settlement_lines: { findMany: fn(), count: fn(), findUnique: fn(), update: fn() },
  auction: { findUnique: fn(), update: fn(), create: fn() },
  admin_audit_log: { findMany: fn(), count: fn() },
  $transaction: fn(),
};

const mockStripe = { enabled: false, createTransfer: fn() };

describe('Phase 0.6 — AdminService', () => {
  let svc: AdminService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        AdminService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: StripeService, useValue: mockStripe },
      ],
    }).compile();
    svc = module.get(AdminService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('AS1: listSettlements — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listSettlements();
    expect(result).toHaveProperty('data');
    expect(result.meta.total).toBe(0);
  });

  it('AS2: getSettlement — not found: throws NotFoundException', async () => {
    mockPrisma.settlements.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getSettlement(999)).rejects.toThrow(NotFoundException);
  });

  it('AS3: listUsers — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listUsers();
    expect(result.meta.total).toBe(0);
  });

  it('AS4: updateUser — not found: throws NotFoundException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(null);
    await expect(svc.updateUser(999, { role: 'admin' })).rejects.toThrow(NotFoundException);
  });

  it('AS5: closeAuction — updates status to closed', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.auction.update.mockResolvedValueOnce({ id: BigInt(1), status: 'closed' });
    const result = await svc.closeAuction(1);
    expect(mockPrisma.auction.update).toHaveBeenCalledWith(expect.objectContaining({ data: { status: 'closed' } }));
    expect((result as any).status).toBe('closed');
  });

  it('AS6: disburseSettlementLine — not found: throws NotFoundException', async () => {
    mockPrisma.settlement_lines.findUnique.mockResolvedValueOnce(null);
    await expect(svc.disburseSettlementLine(999)).rejects.toThrow(NotFoundException);
  });

  it('AS7: disburseSettlementLine — already paid: returns disbursed:false', async () => {
    mockPrisma.settlement_lines.findUnique.mockResolvedValueOnce({ id: BigInt(1), amount_cents: 1000, currency: 'EUR', stripe_account_id: null, status: 'paid' });
    const result = await svc.disburseSettlementLine(1);
    expect(result).toEqual({ disbursed: false, reason: 'already_paid' });
  });

  it('AS8: listLedger — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listLedger();
    expect(result.data).toEqual([]);
  });

  it('AS9: listAuditLog — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listAuditLog();
    expect(result.data).toEqual([]);
  });
});
