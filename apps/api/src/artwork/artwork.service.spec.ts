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
 * AW16 listEvidence — returns evidence for artwork
 * AW17 createEvidence — creates evidence row
 * AW18 listSdgClaims — returns claims for artwork
 * AW19 getEdm — returns EDM context object
 * AW20 getIiifManifest — returns IIIF manifest stub
 * AW21 getIiifInfo — returns IIIF image info stub
 * AW22 listRevisions — returns revisions
 * AW23 createRevision — creates first revision (version 1)
 * AW24 activateRevision — admin: sets status active
 * AW25 activateRevision — non-admin: throws ForbiddenException
 * AW26 createSdgClaim — creates pending claim
 * AW27 getSdgClaim — found: returns claim
 * AW28 getSdgClaim — not found: throws NotFoundException
 * AW29 reviewSdgClaim — admin approve: sets status approved
 * AW30 reviewSdgClaim — non-admin: throws ForbiddenException
 * AW31 getSalesHistory — queries ownership_transfers via art_lots relation
 * AW32 confirmImage — returns confirmed true
 * AW33 listArtLots — returns art lots for artwork
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException, BadRequestException } from '@nestjs/common';
import { ArtworkService } from './artwork.service';
import { PrismaService } from '../prisma.service';
import { S3Service } from '../s3/s3.service';

const STUB = {
  id: BigInt(1), user_id: BigInt(10), title: 'Test Artwork', slug: 'test-artwork',
  medium: 'painting', description: null, status: 'published',
  primary_image_key: null, year_created: 2024, dimensions: null,
  deleted_at: null, created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  artworks: { findMany: fn(), findFirst: fn(), findUnique: fn(), count: fn(), create: fn(), update: fn() },
  artwork_evidence: { findMany: fn(), create: fn() },
  artwork_sdg_claims: { findMany: fn(), findFirst: fn(), create: fn(), update: fn() },
  artwork_revisions: { findMany: fn(), findFirst: fn(), create: fn(), update: fn() },
  art_lots: { findMany: fn() },
  ownership_transfers: { findMany: fn() },
  domain_events: { create: fn() },
  $transaction: fn(),
};

const mockS3 = {
  enabled: false,
  presignUpload: jest.fn() as any,
  stubUploadUrl: (key: string) => `https://stub-s3.local/presign?key=${key}`,
  presignDownload: jest.fn() as any,
  stubDownloadUrl: (key: string) => `https://stub-s3.local/download?key=${key}`,
};

describe('Phase 0.3V — ArtworkService', () => {
  let svc: ArtworkService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        ArtworkService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: S3Service, useValue: mockS3 },
      ],
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

  it('AW11: getRights — reads rights_statement from DB', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artworks.findUnique.mockResolvedValueOnce({
      id: BigInt(1), rights_statement: 'CC BY 4.0', license_spdx: 'CC-BY-4.0', provenance: null,
    });
    const result = await svc.getRights('test-artwork');
    expect(result.artwork_id).toEqual(BigInt(1));
    expect(result.rights_statement).toBe('CC BY 4.0');
    expect(result.license_spdx).toBe('CC-BY-4.0');
  });

  it('AW12: updateRights — admin: persists to DB', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const updated = { id: BigInt(1), rights_statement: 'CC BY 4.0', license_spdx: 'CC-BY-4.0', provenance: null, updated_at: new Date() };
    mockPrisma.artworks.update.mockResolvedValueOnce(updated);
    const result = await svc.updateRights('test-artwork', 1, 'admin', { rights_statement: 'CC BY 4.0' });
    expect(mockPrisma.artworks.update).toHaveBeenCalled();
    expect(result.rights_statement).toBe('CC BY 4.0');
  });

  it('AW12b: updateRights — non-admin: throws ForbiddenException', async () => {
    await expect(svc.updateRights('test-artwork', 1, 'artist', {})).rejects.toThrow(ForbiddenException);
  });

  it('AW13: getQr — returns qr_url', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getQr('test-artwork');
    expect(result.qr_url).toContain('/artworks/');
  });

  it('AW14: presignImage — returns upload_url and quarantine key (stub mode when S3 not configured)', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    // userId 10 matches STUB.user_id (BigInt(10))
    const result = await svc.presignImage('test-artwork', 10, { filename: 'photo.jpg', content_type: 'image/jpeg' });
    expect(result.upload_url).toBeTruthy();
    // Originals go to quarantine prefix, not artworks/ directly
    expect(result.key).toMatch(/^quarantine\/1\//);
    expect(result.key).toMatch(/\.jpg$/);
    expect(result.expires_in).toBe(3600);
  });

  it('AW15: generateDerivatives — non-admin: throws ForbiddenException', async () => {
    await expect(svc.generateDerivatives('test-artwork', 1, 'artist')).rejects.toThrow(ForbiddenException);
  });

  it('AW15b: generateDerivatives — admin: queues domain event and returns queued:true', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.domain_events.create.mockResolvedValueOnce({ id: BigInt(1) });
    const result = await svc.generateDerivatives('test-artwork', 1, 'admin');
    expect(result).toEqual({ queued: true });
    expect(mockPrisma.domain_events.create).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({ event_type: 'artwork.derivatives_requested' }),
      }),
    );
  });

  it('AW16: listEvidence — returns evidence array', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_evidence.findMany.mockResolvedValueOnce([{ id: BigInt(1), type: 'appraisal' }]);
    const result = await svc.listEvidence('test-artwork');
    expect(result).toHaveLength(1);
  });

  it('AW17: createEvidence — creates evidence row', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const ev = { id: BigInt(1), type: 'certificate', issuer: 'MoMA', verification_status: 'pending', created_at: new Date() };
    mockPrisma.artwork_evidence.create.mockResolvedValueOnce(ev);
    const result = await svc.createEvidence('test-artwork', 1, { type: 'certificate', issuer: 'MoMA' });
    expect(result.type).toBe('certificate');
  });

  it('AW18: listSdgClaims — returns claims', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_sdg_claims.findMany.mockResolvedValueOnce([{ id: 1, sdg_number: 4 }]);
    const result = await svc.listSdgClaims('test-artwork');
    expect(result).toHaveLength(1);
  });

  it('AW19: getEdm — returns ProvidedCHO type', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getEdm('test-artwork');
    expect(result.type).toBe('ProvidedCHO');
  });

  it('AW20: getIiifManifest — returns IIIF manifest', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getIiifManifest('test-artwork');
    expect(result.type).toBe('Manifest');
  });

  it('AW21: getIiifInfo — returns IIIF image service', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getIiifInfo('test-artwork');
    expect(result.type).toBe('ImageService3');
  });

  it('AW22: listRevisions — returns revisions', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_revisions.findMany.mockResolvedValueOnce([{ id: BigInt(1), version: 1 }]);
    const result = await svc.listRevisions('test-artwork');
    expect(result).toHaveLength(1);
  });

  it('AW23: createRevision — first revision gets version 1', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_revisions.findFirst.mockResolvedValueOnce(null); // no prior revision
    mockPrisma.artwork_revisions.create.mockResolvedValueOnce({ id: BigInt(1), version: 1, status: 'draft' });
    const result = await svc.createRevision('test-artwork', 1, { title: 'Rev title' });
    expect(result.version).toBe(1);
  });

  it('AW24: activateRevision — admin: sets status active', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_revisions.findFirst.mockResolvedValueOnce({ id: BigInt(1), artwork_id: STUB.id });
    mockPrisma.artwork_revisions.update.mockResolvedValueOnce({ id: BigInt(1), status: 'active' });
    const result = await svc.activateRevision('test-artwork', 1, 1, 'admin');
    expect(result.status).toBe('active');
  });

  it('AW25: activateRevision — non-admin: throws ForbiddenException', async () => {
    await expect(svc.activateRevision('test-artwork', 1, 1, 'artist')).rejects.toThrow(ForbiddenException);
  });

  it('AW26: createSdgClaim — creates pending claim', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_sdg_claims.create.mockResolvedValueOnce({ id: 1, sdg_number: 13, status: 'pending' });
    const result = await svc.createSdgClaim('test-artwork', 1, { sdg_number: 13, rationale: 'Climate action' });
    expect(result.status).toBe('pending');
  });

  it('AW27: getSdgClaim — found: returns claim', async () => {
    mockPrisma.artwork_sdg_claims.findFirst.mockResolvedValueOnce({ id: 1, sdg_number: 13 });
    const result = await svc.getSdgClaim('test-artwork', '1');
    expect(result.sdg_number).toBe(13);
  });

  it('AW28: getSdgClaim — not found: throws NotFoundException', async () => {
    mockPrisma.artwork_sdg_claims.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getSdgClaim('test-artwork', '999')).rejects.toThrow(NotFoundException);
  });

  it('AW29: reviewSdgClaim — admin approve: sets status approved', async () => {
    mockPrisma.artwork_sdg_claims.findFirst.mockResolvedValueOnce({ id: 1 });
    mockPrisma.artwork_sdg_claims.update.mockResolvedValueOnce({ id: 1, status: 'approved' });
    const result = await svc.reviewSdgClaim('test-artwork', '1', 1, 'admin', { approved: true });
    expect(result.status).toBe('approved');
  });

  it('AW30: reviewSdgClaim — non-admin: throws ForbiddenException', async () => {
    await expect(svc.reviewSdgClaim('test-artwork', '1', 1, 'artist', { approved: true })).rejects.toThrow(ForbiddenException);
  });

  it('AW31: getSalesHistory — queries ownership_transfers via art_lots', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    const transfer = { id: BigInt(1), transfer_price_cents: BigInt(100000), currency: 'EUR', channel: 'auction', transferred_at: new Date(), created_at: new Date() };
    mockPrisma.ownership_transfers.findMany.mockResolvedValueOnce([transfer]);
    const result = await svc.getSalesHistory('test-artwork');
    expect(mockPrisma.ownership_transfers.findMany).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.ownership_transfers.findMany.mock.calls[0][0] as any;
    expect(arg.where.art_lots.artwork_id).toEqual(BigInt(1));
    expect(result.data).toHaveLength(1);
  });

  it('AW32: confirmImage — returns confirmed:true and status:processing', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artworks.update.mockResolvedValueOnce({ ...STUB, primary_image_key: 'quarantine/1/img.jpg' });
    // Key must be in quarantine prefix for this artwork (id=1), userId must match owner (10)
    const result = await svc.confirmImage('test-artwork', 10, { key: 'quarantine/1/img.jpg' });
    expect(result.confirmed).toBe(true);
    expect(result.status).toBe('processing');
  });

  it('AW33a: approveImage — non-admin/operator: throws ForbiddenException', async () => {
    await expect(svc.approveImage('test-artwork', 'artist', { approved_key: 'artworks/1/final.jpg' })).rejects.toThrow(ForbiddenException);
  });

  it('AW33b: approveImage — quarantine key rejected', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    await expect(svc.approveImage('test-artwork', 'admin', { approved_key: 'quarantine/1/img.jpg' })).rejects.toThrow(BadRequestException);
  });

  it('AW33c: approveImage — admin with permanent key: marks active', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artworks.update.mockResolvedValueOnce({ ...STUB, primary_image_key: 'artworks/1/final.jpg', primary_image_status: 'active' });
    const result = await svc.approveImage('test-artwork', 'admin', { approved_key: 'artworks/1/final.jpg' });
    expect(result.approved).toBe(true);
    expect(result.key).toBe('artworks/1/final.jpg');
    const updateCall = mockPrisma.artworks.update.mock.calls[0][0] as any;
    expect(updateCall.data.primary_image_status).toBe('active');
  });

  it('AW33d: getImageUrl — throws ForbiddenException when status is not active', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce({ ...STUB, primary_image_status: 'processing', primary_image_key: 'quarantine/1/img.jpg' });
    await expect(svc.getImageUrl('test-artwork')).rejects.toThrow(ForbiddenException);
  });

  it('AW33e: getImageUrl — returns presigned URL when status is active', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce({ ...STUB, primary_image_status: 'active', primary_image_key: 'artworks/1/final.jpg' });
    const result = await svc.getImageUrl('test-artwork');
    expect(result.url).toBeTruthy();
    expect(result.expires_in).toBe(3600);
  });

  it('AW33: listArtLots — returns art lots', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.art_lots.findMany.mockResolvedValueOnce([{ id: BigInt(1), status: 'active' }]);
    const result = await svc.listArtLots('test-artwork');
    expect(result).toHaveLength(1);
  });

  it('AW34: submitArtwork — non-owner non-admin: throws ForbiddenException', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB); // owner_id=10
    await expect(svc.submitArtwork('test-artwork', 99, 'buyer')).rejects.toThrow(ForbiddenException);
    expect(mockPrisma.artworks.update).not.toHaveBeenCalled();
  });

  it('AW35: activateRevision — revision not found: throws NotFoundException', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.artwork_revisions.findFirst.mockResolvedValueOnce(null);
    await expect(svc.activateRevision('test-artwork', 999, 1, 'admin')).rejects.toThrow(NotFoundException);
    expect(mockPrisma.artwork_revisions.update).not.toHaveBeenCalled();
  });

  it('AW36: reviewSdgClaim — claim not found: throws NotFoundException', async () => {
    mockPrisma.artwork_sdg_claims.findFirst.mockResolvedValueOnce(null);
    await expect(svc.reviewSdgClaim('test-artwork', '999', 1, 'admin', { approved: true })).rejects.toThrow(NotFoundException);
    expect(mockPrisma.artwork_sdg_claims.update).not.toHaveBeenCalled();
  });

  it('AW37: getImageUrl — image active but key missing: throws NotFoundException', async () => {
    mockPrisma.artworks.findFirst.mockResolvedValueOnce({ ...STUB, primary_image_status: 'active', primary_image_key: null });
    await expect(svc.getImageUrl('test-artwork')).rejects.toThrow(NotFoundException);
  });
});
