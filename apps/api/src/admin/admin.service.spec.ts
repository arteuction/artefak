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
 * AS10 addAuctionItem — auction not found: throws NotFoundException
 * AS11 addAuctionItem — art lot not found: throws NotFoundException
 * AS12 addAuctionItem — valid: creates and returns item
 * AS13 removeAuctionItem — item not found: throws NotFoundException
 * AS14 removeAuctionItem — non-pending status: throws NotFoundException
 * AS15 removeAuctionItem — pending: removes and returns { removed: true }
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
  art_lots: { findUnique: fn() },
  auctionItem: { findFirst: fn(), create: fn(), delete: fn() },
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

  it('AS10: addAuctionItem — auction not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(null);
    await expect(svc.addAuctionItem(999, { art_lot_id: 1, lot_number: 1 })).rejects.toThrow(NotFoundException);
  });

  it('AS11: addAuctionItem — art lot not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.art_lots.findUnique.mockResolvedValueOnce(null);
    await expect(svc.addAuctionItem(1, { art_lot_id: 999, lot_number: 1 })).rejects.toThrow(NotFoundException);
  });

  it('AS12: addAuctionItem — valid: creates and returns item', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.art_lots.findUnique.mockResolvedValueOnce({ id: BigInt(5) });
    const created = { id: BigInt(10), auction_id: BigInt(1), art_lot_id: BigInt(5), lot_number: 1, bid_increment_cents: null, status: 'pending' };
    mockPrisma.auctionItem.create.mockResolvedValueOnce(created);
    const result = await svc.addAuctionItem(1, { art_lot_id: 5, lot_number: 1 });
    expect((result as any).status).toBe('pending');
    expect(mockPrisma.auctionItem.create).toHaveBeenCalled();
  });

  it('AS13: removeAuctionItem — item not found: throws NotFoundException', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce(null);
    await expect(svc.removeAuctionItem(1, 999)).rejects.toThrow(NotFoundException);
  });

  it('AS14: removeAuctionItem — non-pending status: throws NotFoundException', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1), status: 'open' });
    await expect(svc.removeAuctionItem(1, 1)).rejects.toThrow(NotFoundException);
  });

  it('AS15: removeAuctionItem — pending: removes and returns removed:true', async () => {
    mockPrisma.auctionItem.findFirst.mockResolvedValueOnce({ id: BigInt(1), status: 'pending' });
    mockPrisma.auctionItem.delete.mockResolvedValueOnce({ id: BigInt(1) });
    const result = await svc.removeAuctionItem(1, 1);
    expect(result.removed).toBe(true);
    expect(mockPrisma.auctionItem.delete).toHaveBeenCalled();
  });
});
