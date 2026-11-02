/**
 * Phase 0.3U â€” DonationRecipientService unit tests
 *
 * DR1  listRecipients default pagination
 * DR2  listRecipients custom pagination â€” last_page calculation
 * DR3  getRecipient   â€” found
 * DR4  getRecipient   â€” not found: throws NotFoundException
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
  donation_recipients: { findMany: fn(), findFirst: fn(), count: fn() },
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
});
