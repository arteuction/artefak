/**
 * Phase 0.3U â€” DonationRecipientService unit tests
 *
 * DR1  listRecipients default pagination
 * DR2  listRecipients custom pagination â€” last_page calculation
 * DR3  getRecipient   â€” found
 * DR4  getRecipient   â€” not found: throws NotFoundException
 * DR5  createRecipient â€” writes to donation_recipients table
 * DR6  updateRecipient â€” found: persists changes
 * DR7  updateRecipient â€” not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { DonationRecipientService } from './donation-recipient.service';
import { PrismaService } from '../prisma.service';

const STUB_RECIPIENT = {
  id: BigInt(1), name: 'Bulgarian Red Cross', legal_type: 'ngo',
  eligibility_basis: 'art31', deduction_bps: 1000, status: 'active', created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  donation_recipients: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” DonationRecipientService', () => {
  let svc: DonationRecipientService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [DonationRecipientService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(DonationRecipientService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('DR1: listRecipients default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_RECIPIENT], 1]);
    const result = await svc.listRecipients();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
    expect(result.meta.total).toBe(1);
  });

  it('DR2: listRecipients â€” last_page calculated correctly', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 25]);
    const result = await svc.listRecipients(1, 10);
    expect(result.meta.last_page).toBe(3);
  });

  it('DR3: getRecipient â€” found', async () => {
    mockPrisma.donation_recipients.findFirst.mockResolvedValueOnce(STUB_RECIPIENT);
    const result = await svc.getRecipient(1);
    expect(result.name).toBe('Bulgarian Red Cross');
  });

  it('DR4: getRecipient â€” not found: throws NotFoundException', async () => {
    mockPrisma.donation_recipients.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getRecipient(999)).rejects.toThrow(NotFoundException);
  });

  it('DR5: createRecipient — writes to donation_recipients table', async () => {
    mockPrisma.donation_recipients.create.mockResolvedValueOnce({ ...STUB_RECIPIENT, id: BigInt(2) });
    const result = await svc.createRecipient({ name: 'Caritas BG', legal_type: 'ngo', deduction_bps: 500 });
    expect(mockPrisma.donation_recipients.create).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.donation_recipients.create.mock.calls[0][0] as any;
    expect(arg.data.name).toBe('Caritas BG');
    expect(arg.data.deduction_bps).toBe(500);
    expect(arg.data.status).toBe('active');
    expect(result.id).toEqual(BigInt(2));
  });

  it('DR6: updateRecipient — found: persists deduction_bps change', async () => {
    mockPrisma.donation_recipients.findFirst.mockResolvedValueOnce(STUB_RECIPIENT);
    const updated = { id: BigInt(1), name: 'Bulgarian Red Cross', status: 'active', updated_at: new Date() };
    mockPrisma.donation_recipients.update.mockResolvedValueOnce(updated);
    const result = await svc.updateRecipient(1, { deduction_bps: 2000 });
    expect(mockPrisma.donation_recipients.update).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.donation_recipients.update.mock.calls[0][0] as any;
    expect(arg.where.id).toEqual(BigInt(1));
    expect(arg.data.deduction_bps).toBe(2000);
    expect(result.status).toBe('active');
  });

  it('DR7: updateRecipient — not found: throws NotFoundException', async () => {
    mockPrisma.donation_recipients.findFirst.mockResolvedValueOnce(null);
    await expect(svc.updateRecipient(999, { name: 'X' })).rejects.toThrow(NotFoundException);
  });
});
