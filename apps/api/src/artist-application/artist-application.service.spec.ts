/**
 * Phase 0.3V — ArtistApplicationService unit tests
 *
 * AA1  listApplications — admin sees all
 * AA2  listApplications — non-admin scoped to own profile
 * AA3  getApplication — found, owner: returns it
 * AA4  getApplication — not found: throws NotFoundException
 * AA5  getApplication — found, different user: throws ForbiddenException
 * AA6  createApplication — no profile: throws NotFoundException
 * AA7  createApplication — profile found: creates draft
 * AA8  reviewApplication — approve: sets status approved
 * AA9  reviewApplication — reject: sets status rejected
 * AA10 reviewApplication — not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { ArtistApplicationService } from './artist-application.service';
import { PrismaService } from '../prisma.service';

const STUB_APP = {
  id: BigInt(1), artist_profile_id: BigInt(1), status: 'draft',
  portfolio_url: null, motivation: null, review_note: null,
  version: 1, created_at: new Date(),
  artist_profiles: { user_id: BigInt(10) },
};

const STUB_PROFILE = { id: BigInt(1), user_id: BigInt(10), deleted_at: null };

const fn = () => jest.fn() as any;
const mockPrisma = {
  artist_applications: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() },
  artist_profiles: { findFirst: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — ArtistApplicationService', () => {
  let svc: ArtistApplicationService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ArtistApplicationService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ArtistApplicationService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('AA1: listApplications — admin sees all', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_APP], 1]);
    const result = await svc.listApplications(1, 'admin');
    expect(result.data).toHaveLength(1);
  });

  it('AA2: listApplications — artist scoped to own', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listApplications(99, 'artist');
    expect(result.meta.total).toBe(0);
  });

  it('AA3: getApplication — owner: returns it', async () => {
    mockPrisma.artist_applications.findFirst.mockResolvedValueOnce(STUB_APP);
    const result = await svc.getApplication(1, 10, 'artist');
    expect(result.id).toEqual(BigInt(1));
  });

  it('AA4: getApplication — not found: throws NotFoundException', async () => {
    mockPrisma.artist_applications.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getApplication(999, 1, 'artist')).rejects.toThrow(NotFoundException);
  });

  it('AA5: getApplication — different user: throws ForbiddenException', async () => {
    mockPrisma.artist_applications.findFirst.mockResolvedValueOnce(STUB_APP); // user_id=10
    await expect(svc.getApplication(1, 99, 'artist')).rejects.toThrow(ForbiddenException);
  });

  it('AA6: createApplication — no profile: throws NotFoundException', async () => {
    mockPrisma.artist_profiles.findFirst.mockResolvedValueOnce(null);
    await expect(svc.createApplication(10, {})).rejects.toThrow(NotFoundException);
  });

  it('AA7: createApplication — profile found: creates draft', async () => {
    mockPrisma.artist_profiles.findFirst.mockResolvedValueOnce(STUB_PROFILE);
    mockPrisma.artist_applications.create.mockResolvedValueOnce(STUB_APP);
    const result = await svc.createApplication(10, { portfolio_url: 'https://portfolio.example.com' });
    expect(result.status).toBe('draft');
  });

  it('AA8: reviewApplication — approve: sets status approved', async () => {
    mockPrisma.artist_applications.findFirst.mockResolvedValueOnce(STUB_APP);
    mockPrisma.artist_applications.update.mockResolvedValueOnce({ ...STUB_APP, status: 'approved' });
    const result = await svc.reviewApplication(1, 1, 'approved', 'Great portfolio');
    expect(result.status).toBe('approved');
  });

  it('AA9: reviewApplication — reject: sets status rejected', async () => {
    mockPrisma.artist_applications.findFirst.mockResolvedValueOnce(STUB_APP);
    mockPrisma.artist_applications.update.mockResolvedValueOnce({ ...STUB_APP, status: 'rejected' });
    const result = await svc.reviewApplication(1, 1, 'rejected', 'Insufficient work');
    expect(result.status).toBe('rejected');
  });

  it('AA10: reviewApplication — not found: throws NotFoundException', async () => {
    mockPrisma.artist_applications.findFirst.mockResolvedValueOnce(null);
    await expect(svc.reviewApplication(999, 1, 'approved', 'notes')).rejects.toThrow(NotFoundException);
    expect(mockPrisma.artist_applications.update).not.toHaveBeenCalled();
  });
});
