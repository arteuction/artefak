/**
 * Phase 0.3U â€” InstitutionService unit tests
 *
 * IS1  listInstitutions default pagination
 * IS2  listInstitutions custom pagination
 * IS3  getInstitution by slug â€” found
 * IS4  getInstitution by slug â€” not found: throws NotFoundException
 * IS5  getInstitution by numeric id â€” found
 * IS6  createInstitution â€” delegates to prisma.create
 * IS7  updateInstitution â€” found and updated
 * IS8  updateInstitution â€” not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { InstitutionService } from './institution.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), name: 'National Gallery', slug: 'national-gallery',
  type: 'gallery', country_code: 'BG', website_url: 'https://nationalgallery.bg',
  contact_email: 'info@ng.bg', is_active: true, created_at: new Date(), deleted_at: null,
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  institutions: { findMany: fn(), findFirst: fn(), create: fn(), update: fn(), count: fn() },
  institution_artworks: { create: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” InstitutionService', () => {
  let svc: InstitutionService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [InstitutionService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(InstitutionService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('IS1: listInstitutions default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listInstitutions();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
  });

  it('IS2: listInstitutions custom pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listInstitutions(2, 5);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(5);
  });

  it('IS3: getInstitution by slug â€” found', async () => {
    mockPrisma.institutions.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getInstitution('national-gallery');
    expect(result.name).toBe('National Gallery');
  });

  it('IS4: getInstitution by slug â€” not found: throws NotFoundException', async () => {
    mockPrisma.institutions.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getInstitution('missing')).rejects.toThrow(NotFoundException);
  });

  it('IS5: getInstitution by numeric id â€” found', async () => {
    mockPrisma.institutions.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getInstitution('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('IS6: createInstitution â€” delegates to prisma.create', async () => {
    mockPrisma.institutions.create.mockResolvedValueOnce(STUB);
    const result = await svc.createInstitution({ name: 'National Gallery', slug: 'national-gallery' });
    expect(result.name).toBe('National Gallery');
    expect(mockPrisma.institutions.create).toHaveBeenCalled();
  });

  it('IS7: updateInstitution â€” found: updates and returns', async () => {
    mockPrisma.institutions.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.institutions.update.mockResolvedValueOnce({ ...STUB, name: 'Updated Gallery' });
    const result = await svc.updateInstitution('national-gallery', { name: 'Updated Gallery' });
    expect(result.name).toBe('Updated Gallery');
  });

  it('IS8: updateInstitution â€” not found: throws NotFoundException', async () => {
    mockPrisma.institutions.findFirst.mockResolvedValueOnce(null);
    await expect(svc.updateInstitution('missing', { name: 'X' })).rejects.toThrow(NotFoundException);
  });
});
