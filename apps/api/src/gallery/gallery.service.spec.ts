/**
 * Phase 0.3V — GalleryService unit tests
 *
 * GS1  listGalleries default pagination
 * GS2  listGalleries with type filter
 * GS3  getGallery by numeric id — found
 * GS4  getGallery by slug — found
 * GS5  getGallery — not found: throws NotFoundException
 * GS6  createGallery — delegates to prisma.create
 * GS7  updateGallery — admin: delegates to prisma.update
 * GS8  updateGallery — non-admin: throws ForbiddenException
 * GS9  listStaff — delegates to prisma.findMany
 * GS10 addStaff — non-admin: throws ForbiddenException
 * GS11 removeStaff — not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { GalleryService } from './gallery.service';
import { PrismaService } from '../prisma.service';

const STUB_GALLERY = {
  id: BigInt(1), name: 'Test Gallery', slug: 'test-gallery', type: 'private',
  status: 'active', website: null, contact_email: null, legal_name: null,
  created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  galleries: { findMany: fn(), findUnique: fn(), count: fn(), create: fn(), update: fn() },
  gallery_staff: { findMany: fn(), findFirst: fn(), create: fn(), update: fn(), delete: fn() },
  art_lots: { findMany: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — GalleryService', () => {
  let svc: GalleryService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [GalleryService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(GalleryService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('GS1: listGalleries default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_GALLERY], 1]);
    const result = await svc.listGalleries();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
  });

  it('GS2: listGalleries with type filter', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listGalleries({ type: 'public' });
    expect(result.meta.total).toBe(0);
  });

  it('GS3: getGallery by numeric id — found', async () => {
    // numeric: one findUnique({ where: { id } })
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    const result = await svc.getGallery('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('GS4: getGallery by slug — found', async () => {
    // non-numeric: byId=null (no call), then findUnique({ where: { slug } })
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    const result = await svc.getGallery('test-gallery');
    expect(result.slug).toBe('test-gallery');
  });

  it('GS5: getGallery — not found: throws NotFoundException', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getGallery('missing')).rejects.toThrow(NotFoundException);
  });

  it('GS6: createGallery — delegates to prisma.create', async () => {
    mockPrisma.galleries.create.mockResolvedValueOnce(STUB_GALLERY);
    const result = await svc.createGallery({ name: 'Test Gallery', slug: 'test-gallery' });
    expect(mockPrisma.galleries.create).toHaveBeenCalled();
    expect(result.name).toBe('Test Gallery');
  });

  it('GS7: updateGallery — admin: delegates to prisma.update', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    mockPrisma.galleries.update.mockResolvedValueOnce({ ...STUB_GALLERY, name: 'Updated' });
    const result = await svc.updateGallery('test-gallery', 1, 'admin', { name: 'Updated' });
    expect(result.name).toBe('Updated');
  });

  it('GS8: updateGallery — non-admin: throws ForbiddenException', async () => {
    await expect(svc.updateGallery('test-gallery', 1, 'buyer', { name: 'X' })).rejects.toThrow(ForbiddenException);
  });

  it('GS9: listStaff — delegates to prisma.findMany', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    mockPrisma.gallery_staff.findMany.mockResolvedValueOnce([]);
    const result = await svc.listStaff('test-gallery');
    expect(Array.isArray(result)).toBe(true);
  });

  it('GS10: addStaff — non-admin: throws ForbiddenException', async () => {
    await expect(svc.addStaff('test-gallery', 1, 'buyer', { user_id: 2, role: 'agent' })).rejects.toThrow(ForbiddenException);
  });

  it('GS11: removeStaff — staff not found: throws NotFoundException', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    mockPrisma.gallery_staff.findFirst.mockResolvedValueOnce(null);
    await expect(svc.removeStaff('test-gallery', 99, 1, 'admin')).rejects.toThrow(NotFoundException);
  });

  it('GS12: getGalleryProfile — returns gallery with staff', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY); // getGallery by slug
    mockPrisma.galleries.findUnique.mockResolvedValueOnce({ ...STUB_GALLERY, gallery_staff: [] }); // profile
    const result = await svc.getGalleryProfile('test-gallery');
    expect(result).toBeTruthy();
  });

  it('GS13: updateStaff — admin: updates staff member', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    mockPrisma.gallery_staff.findFirst.mockResolvedValueOnce({ id: BigInt(1), gallery_id: STUB_GALLERY.id });
    mockPrisma.gallery_staff.update.mockResolvedValueOnce({ id: BigInt(1), role: 'senior', status: 'active' });
    const result = await svc.updateStaff('test-gallery', 1, 1, 'admin', { role: 'senior' });
    expect(result.role).toBe('senior');
  });

  it('GS14: updateStaff — non-admin: throws ForbiddenException', async () => {
    await expect(svc.updateStaff('test-gallery', 1, 1, 'artist', { role: 'senior' })).rejects.toThrow(ForbiddenException);
  });

  it('GS15: listGalleryLots — returns art lots for gallery', async () => {
    mockPrisma.galleries.findUnique.mockResolvedValueOnce(STUB_GALLERY);
    mockPrisma.art_lots.findMany.mockResolvedValueOnce([{ id: BigInt(1), status: 'active' }]);
    const result = await svc.listGalleryLots('test-gallery');
    expect(result).toHaveLength(1);
  });
});
