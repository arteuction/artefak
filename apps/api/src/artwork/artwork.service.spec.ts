/**
 * Phase 0.3V — ArtworkService unit tests
 *
 * AW1  listArtworks default pagination
 * AW2  listArtworks with status + medium filters
 * AW3  getArtwork by numeric id — found
 * AW4  getArtwork by slug — found (single findFirst call, not numeric)
 * AW5  getArtwork — not found: throws NotFoundException
 * AW6  createArtwork — delegates to prisma.create
 * AW7  updateArtwork — owner updating: delegates to prisma.update
 * AW8  updateArtwork — non-owner non-admin: throws ForbiddenException
 * AW9  submitArtwork — owner: sets status to review
 * AW10 getLinkedArt — returns linked-art context
 * AW11 getRights — returns artwork_id and rights_statement
 * AW12 updateRights — non-admin: throws ForbiddenException
 * AW13 getQr — returns qr_url
 * AW14 presignImage — returns upload_url and key
 * AW15 generateDerivatives — non-admin: throws ForbiddenException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { ArtworkService } from './artwork.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), user_id: BigInt(10), title: 'Test Artwork', slug: 'test-artwork',
  medium: 'painting', description: null, status: 'published',
  primary_image_key: null, year_created: 2024, dimensions: null,
  deleted_at: null, created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  artworks: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn() },
  artwork_evidence: { findMany: fn(), create: fn() },
  artwork_sdg_claims: { findMany: fn(), findFirst: fn(), create: fn(), update: fn() },
  artwork_revisions: { findMany: fn(), findFirst: fn(), create: fn(), update: fn() },
  art_lots: { findMany: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — ArtworkService', () => {
  let svc: ArtworkService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [ArtworkService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(ArtworkService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('AW1: listArtworks default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listArtworks();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('AW2: listArtworks with status + medium filters', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listArtworks({ status: 'draft', medium: 'sculpture' });
    expect(result.data).toHaveLength(0);
  });

  it('AW3: getArtwork by numeric id — found', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getArtwork('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('AW4: getArtwork by slug — found', async () => {
    // non-numeric: byId=null (no call), one findFirst for slug
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getArtwork('test-artwork');
    expect(result.slug).toBe('test-artwork');
  });

  it('AW5: getArtwork — not found: throws NotFoundException', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getArtwork('missing')).rejects.toThrow(NotFoundException);
  });

  it('AW6: createArtwork — delegates to prisma.create', async () => {
    mockPrisma.artworks.create.mockResolvedValueOnce(STUB);
    const result = await svc.createArtwork(10, { title: 'Test Artwork', slug: 'test-artwork' });
    expect(mockPrisma.artworks.create).toHaveBeenCalled();
    expect(result.title).toBe('Test Artwork');
  });

  it('AW7: updateArtwork — owner: delegates to prisma.update', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artworks.update.mockResolvedValueOnce({ ...STUB, title: 'Updated' });
    const result = await svc.updateArtwork('test-artwork', 10, 'artist', { title: 'Updated' });
    expect(result.title).toBe('Updated');
  });

  it('AW8: updateArtwork — non-owner non-admin: throws ForbiddenException', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB); // user_id=10
    await expect(svc.updateArtwork('test-artwork', 99, 'buyer', { title: 'X' })).rejects.toThrow(ForbiddenException);
  });

  it('AW9: submitArtwork — owner: sets status to review', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artworks.update.mockResolvedValueOnce({ ...STUB, status: 'review' });
    const result = await svc.submitArtwork('test-artwork', 10, 'artist');
    expect(result.status).toBe('review');
  });

  it('AW10: getLinkedArt — returns linked-art context', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getLinkedArt('test-artwork');
    expect(result['@context']).toContain('linked.art');
  });

  it('AW11: getRights — returns artwork_id and rights_statement', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getRights('test-artwork');
    expect(result.artwork_id).toEqual(BigInt(1));
    expect(result.rights_statement).toBeNull();
  });

  it('AW12: updateRights — non-admin: throws ForbiddenException', async () => {
    await expect(svc.updateRights('test-artwork', 1, 'artist', {})).rejects.toThrow(ForbiddenException);
  });

  it('AW13: getQr — returns qr_url', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getQr('test-artwork');
    expect(result.qr_url).toContain('/artworks/');
  });

  it('AW14: presignImage — returns upload_url and key', async () => {
    const result = await svc.presignImage('test-artwork', 1, { filename: 'photo.jpg', content_type: 'image/jpeg' });
    expect(result.upload_url).toBeTruthy();
    expect(result.key).toContain('photo.jpg');
  });

  it('AW15: generateDerivatives — non-admin: throws ForbiddenException', async () => {
    await expect(svc.generateDerivatives('test-artwork', 1, 'artist')).rejects.toThrow(ForbiddenException);
  });
});
