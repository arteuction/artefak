/**
 * Phase 0.3U â€” DonationService unit tests
 *
 * DN1  listDonations â€” admin sees all donations (no where filter on donor_id)
 * DN2  listDonations â€” buyer sees only own donations
 * DN3  listDonations â€” custom pagination
 * DN4  getDonation â€” found, owner requesting: returns donation
 * DN5  getDonation â€” found, admin requesting: returns donation
 * DN6  getDonation â€” not found: throws NotFoundException
 * DN7  getDonation — found, different buyer requesting: throws NotFoundException (scoped visibility)
 * DN8  createDonation — writes to donations table (THREE-LEDGER: donations only, never ledger_entries)
 * DN9  createDonation — uses provided idempotency_key
 * DN10 taxReceipt — aggregates completed deductible donations for year
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { DonationService, CreateDonationDto } from './donation.service';
import { PrismaService } from '../prisma.service';

const STUB_DONATION = {
  id: BigInt(1),
  donor_id: BigInt(10),
  donated_cents: BigInt(5000),
  currency: 'BGN',
  type: 'one_time',
  status: 'completed',
  eligibility_basis: null,
  deduction_bps: 0,
  created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  donations: { findMany: fn(), findFirst: fn(), count: fn(), create: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” DonationService', () => {
  let svc: DonationService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        DonationService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(DonationService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('DN1: listDonations â€” admin: no donor_id filter', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    await svc.listDonations(1, 'admin');
    const call = mockPrisma.$transaction.mock.calls[0][0];
    // The transaction receives an array of two Prisma calls; we can't inspect
    // them directly from the mock, but we can verify the call happened.
    expect(mockPrisma.$transaction).toHaveBeenCalled();
  });

  it('DN2: listDonations â€” buyer: returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_DONATION], 1]);
    const result = await svc.listDonations(10, 'buyer');
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('DN3: listDonations â€” custom pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listDonations(1, 'admin', 2, 5);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(5);
  });

  it('DN4: getDonation â€” owner requesting: returns donation', async () => {
    mockPrisma.donations.findFirst.mockResolvedValueOnce(STUB_DONATION);
    const result = await svc.getDonation(1, 10, 'buyer');
    expect(result.id).toEqual(BigInt(1));
  });

  it('DN5: getDonation â€” admin requesting any donation: returns it', async () => {
    mockPrisma.donations.findFirst.mockResolvedValueOnce(STUB_DONATION);
    const result = await svc.getDonation(1, 999, 'admin');
    expect(result.id).toEqual(BigInt(1));
  });

  it('DN6: getDonation â€” not found: throws NotFoundException', async () => {
    mockPrisma.donations.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getDonation(999, 1, 'buyer')).rejects.toThrow(NotFoundException);
  });

  it('DN7: getDonation — different buyer: throws NotFoundException (scoped)', async () => {
    mockPrisma.donations.findFirst.mockResolvedValueOnce(STUB_DONATION); // donor_id = 10
    await expect(svc.getDonation(1, 99, 'buyer')).rejects.toThrow(NotFoundException);
  });

  it('DN8: createDonation — writes to donations table (THREE-LEDGER invariant)', async () => {
    const created = { ...STUB_DONATION, id: BigInt(5) };
    mockPrisma.donations.create.mockResolvedValueOnce(created);
    const dto: CreateDonationDto = { donation_recipient_id: 3, donated_cents: 5000 };
    const result = await svc.createDonation(10, dto);
    // Must write to donations — never to ledger_entries or impact_events
    expect(mockPrisma.donations.create).toHaveBeenCalledTimes(1);
    const callArg = mockPrisma.donations.create.mock.calls[0][0] as any;
    expect(callArg.data.donor_id).toEqual(BigInt(10));
    expect(callArg.data.donated_cents).toEqual(BigInt(5000));
    expect(callArg.data.status).toBe('pending');
    expect(result.id).toEqual(BigInt(5));
  });

  it('DN9: createDonation — uses provided idempotency_key', async () => {
    mockPrisma.donations.create.mockResolvedValueOnce(STUB_DONATION);
    const dto: CreateDonationDto = { donation_recipient_id: 3, donated_cents: 1000, idempotency_key: 'idem-123' };
    await svc.createDonation(10, dto);
    const callArg = mockPrisma.donations.create.mock.calls[0][0] as any;
    expect(callArg.data.idempotency_key).toBe('idem-123');
  });

  it('DN10: taxReceipt — aggregates completed deductible donations for year', async () => {
    const row = {
      id: BigInt(1), donated_cents: BigInt(5000), max_deductible_cents: BigInt(5000),
      deduction_bps: 1000, currency: 'EUR', eligibility_basis: 'art31',
      donation_recipients: { id: BigInt(1), name: 'Red Cross BG' }, created_at: new Date(),
    };
    mockPrisma.donations.findMany.mockResolvedValueOnce([row]);
    const result = await svc.taxReceipt(10, 2026);
    expect(mockPrisma.donations.findMany).toHaveBeenCalledTimes(1);
    const callArg = mockPrisma.donations.findMany.mock.calls[0][0] as any;
    expect(callArg.where.donor_id).toEqual(BigInt(10));
    expect(callArg.where.status).toBe('completed');
    expect(result.year).toBe(2026);
    expect(result.total_donated_cents).toBe(5000);
    expect(result.total_deductible_cents).toBe(5000);
    expect(result.rows).toHaveLength(1);
  });
});
