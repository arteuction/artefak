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
 * AS16 getSettlement — found: returns settlement with lines
 * AS17 listRefunds — returns paged result
 * AS18 getUser — found: returns user
 * AS19 getUser — not found: throws NotFoundException
 * AS20 updateUser — found: updates and returns user
 * AS21 listTransferOutbox — returns paged result
 * AS22 getTransferOutboxItem — found: returns item
 * AS23 getTransferOutboxItem — not found: throws NotFoundException
 * AS24 createAuction — creates and returns draft auction
 * AS25 publishAuction — not found: throws NotFoundException
 * AS26 publishAuction — found: sets status published
 * AS27 openAuction — found: sets status open
 * AS28 listSettlementLines — returns paged result
 * AS29 disburseSettlementLine — unpaid without Stripe: marks paid and returns disbursed:true
 * AS30 updateAuction — not found: throws NotFoundException
 * AS31 openAuction — not found: throws NotFoundException
 * AS32 closeAuction — not found: throws NotFoundException
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

  it('AS16: getSettlement — found: returns settlement with lines', async () => {
    const stub = { id: BigInt(1), status: 'completed', settlement_lines: [] };
    mockPrisma.settlements.findUnique.mockResolvedValueOnce(stub);
    const result = await svc.getSettlement(1);
    expect((result as any).id).toEqual(BigInt(1));
  });

  it('AS17: listRefunds — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listRefunds();
    expect(result.data).toEqual([]);
    expect(result.meta.total).toBe(0);
  });

  it('AS18: getUser — found: returns user', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce({ id: BigInt(5), email: 'u@test.com', name: 'Test', role: 'buyer', email_verified_at: null, created_at: new Date(), updated_at: new Date() });
    const result = await svc.getUser(5);
    expect((result as any).email).toBe('u@test.com');
  });

  it('AS19: getUser — not found: throws NotFoundException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getUser(999)).rejects.toThrow(NotFoundException);
  });

  it('AS20: updateUser — found: updates and returns user', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce({ id: BigInt(2) });
    mockPrisma.user.update.mockResolvedValueOnce({ id: BigInt(2), email: 'u@test.com', name: 'New Name', role: 'admin', updated_at: new Date() });
    const result = await svc.updateUser(2, { name: 'New Name' });
    expect((result as any).name).toBe('New Name');
    expect(mockPrisma.user.update).toHaveBeenCalled();
  });

  it('AS21: listTransferOutbox — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[{ id: BigInt(1) }], 1]);
    const result = await svc.listTransferOutbox();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('AS22: getTransferOutboxItem — found: returns item', async () => {
    mockPrisma.transfer_outbox.findUnique.mockResolvedValueOnce({ id: BigInt(3), status: 'pending' });
    const result = await svc.getTransferOutboxItem(3);
    expect((result as any).id).toEqual(BigInt(3));
  });

  it('AS23: getTransferOutboxItem — not found: throws NotFoundException', async () => {
    mockPrisma.transfer_outbox.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getTransferOutboxItem(999)).rejects.toThrow(NotFoundException);
  });

  it('AS24: createAuction — creates and returns draft auction', async () => {
    const created = { id: BigInt(7), title: 'New Auction', slug: 'new-auction', status: 'draft', starts_at: new Date(), ends_at: new Date() };
    mockPrisma.auction.create.mockResolvedValueOnce(created);
    const result = await svc.createAuction({ title: 'New Auction', slug: 'new-auction', starts_at: '2027-01-01T00:00:00Z', ends_at: '2027-01-31T00:00:00Z' });
    expect((result as any).status).toBe('draft');
    expect(mockPrisma.auction.create).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ title: 'New Auction', status: 'draft' }) })
    );
  });

  it('AS25: publishAuction — not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(null);
    await expect(svc.publishAuction(999)).rejects.toThrow(NotFoundException);
  });

  it('AS26: publishAuction — found: sets status published', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.auction.update.mockResolvedValueOnce({ id: BigInt(1), status: 'published' });
    const result = await svc.publishAuction(1);
    expect((result as any).status).toBe('published');
  });

  it('AS27: openAuction — found: sets status open', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.auction.update.mockResolvedValueOnce({ id: BigInt(1), status: 'open' });
    const result = await svc.openAuction(1);
    expect((result as any).status).toBe('open');
  });

  it('AS28: listSettlementLines — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[{ id: BigInt(1), amount_cents: BigInt(4500) }], 1]);
    const result = await svc.listSettlementLines();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('AS29: disburseSettlementLine — unpaid, no Stripe: marks paid and returns disbursed:true', async () => {
    mockPrisma.settlement_lines.findUnique.mockResolvedValueOnce({ id: BigInt(2), amount_cents: 5000, currency: 'EUR', stripe_account_id: null, status: 'pending' });
    mockPrisma.settlement_lines.update.mockResolvedValueOnce({ id: BigInt(2), status: 'paid' });
    const result = await svc.disburseSettlementLine(2);
    expect(result).toEqual({ disbursed: true, line_id: 2 });
    expect(mockPrisma.settlement_lines.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ status: 'paid' }) })
    );
  });

  it('AS30: updateAuction — not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(null);
    await expect(svc.updateAuction(999, { title: 'X' })).rejects.toThrow(NotFoundException);
    expect(mockPrisma.auction.update).not.toHaveBeenCalled();
  });

  it('AS31: openAuction — not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(null);
    await expect(svc.openAuction(999)).rejects.toThrow(NotFoundException);
    expect(mockPrisma.auction.update).not.toHaveBeenCalled();
  });

  it('AS32: closeAuction — not found: throws NotFoundException', async () => {
    mockPrisma.auction.findUnique.mockResolvedValueOnce(null);
    await expect(svc.closeAuction(999)).rejects.toThrow(NotFoundException);
    expect(mockPrisma.auction.update).not.toHaveBeenCalled();
  });
});
