/**
 * Phase 0.3V — CollectionService unit tests
 *
 * CS1  listPublicCollections default pagination
 * CS2  getCollection by numeric id — found
 * CS3  getCollection by slug — found
 * CS4  getCollection — not found: throws NotFoundException
 * CS5  createCollection — delegates to prisma.create
 * CS6  updateCollection — owner: delegates to prisma.update
 * CS7  updateCollection — non-owner non-admin: throws ForbiddenException
 * CS8  deleteCollection — owner: deletes and returns { deleted: true }
 * CS9  deleteCollection — non-owner: throws ForbiddenException
 * CS10 getCollectionArtworks — queries collection_artworks scoped to collection id
 * CS11 addArtwork — creates collection_artworks row with computed position
 * CS12 removeArtwork — deletes row; throws NotFoundException if artwork not in collection
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException, ForbiddenException } from '@nestjs/common';
import { CollectionService } from './collection.service';
import { PrismaService } from '../prisma.service';

const STUB = {
  id: BigInt(1), owner_id: BigInt(10), title: 'My Collection', slug: 'my-collection',
  description: null, cover_image_url: null, visibility: 'public', created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  collections: { findMany: fn(), findFirst: fn(), count: fn(), create: fn(), update: fn(), delete: fn() },
  collection_artworks: { findMany: fn(), findFirst: fn(), create: fn(), delete: fn(), aggregate: fn() },
  $transaction: fn(),
};

describe('Phase 0.3V — CollectionService', () => {
  let svc: CollectionService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [CollectionService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(CollectionService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('CS1: listPublicCollections default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB], 1]);
    const result = await svc.listPublicCollections();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('CS2: getCollection by numeric id — found', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getCollection('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('CS3: getCollection by slug — found', async () => {
    // non-numeric: byId=null (no call), one findFirst for slug
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB);
    const result = await svc.getCollection('my-collection');
    expect(result.slug).toBe('my-collection');
  });

  it('CS4: getCollection — not found: throws NotFoundException', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getCollection('missing')).rejects.toThrow(NotFoundException);
  });

  it('CS5: createCollection — delegates to prisma.create', async () => {
    mockPrisma.collections.create.mockResolvedValueOnce(STUB);
    const result = await svc.createCollection(10, { title: 'My Collection', slug: 'my-collection' });
    expect(mockPrisma.collections.create).toHaveBeenCalled();
    expect(result.title).toBe('My Collection');
  });

  it('CS6: updateCollection — owner: delegates to prisma.update', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB); // owner_id=10
    mockPrisma.collections.update.mockResolvedValueOnce({ ...STUB, title: 'Updated' });
    const result = await svc.updateCollection('my-collection', 10, 'buyer', { title: 'Updated' });
    expect(result.title).toBe('Updated');
  });

  it('CS7: updateCollection — non-owner non-admin: throws ForbiddenException', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB); // owner_id=10
    await expect(svc.updateCollection('my-collection', 99, 'buyer', { title: 'X' })).rejects.toThrow(ForbiddenException);
  });

  it('CS8: deleteCollection — owner: deletes and returns { deleted: true }', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.collections.delete.mockResolvedValueOnce({});
    const result = await svc.deleteCollection('my-collection', 10, 'buyer');
    expect(result.deleted).toBe(true);
  });

  it('CS9: deleteCollection — non-owner: throws ForbiddenException', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB); // owner_id=10
    await expect(svc.deleteCollection('my-collection', 99, 'buyer')).rejects.toThrow(ForbiddenException);
  });

  it('CS10: getCollectionArtworks — queries collection_artworks scoped to collection id', async () => {
    const artworkRow = { artworks: { id: BigInt(5), title: 'Sunrise', slug: 'sunrise', medium: 'oil', status: 'published', primary_image_key: null, year_created: 2020 }, position: 1 };
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.collection_artworks.findMany.mockResolvedValueOnce([artworkRow]);
    const result = await svc.getCollectionArtworks('1');
    expect(mockPrisma.collection_artworks.findMany).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.collection_artworks.findMany.mock.calls[0][0] as any;
    expect(arg.where.collection_id).toEqual(BigInt(1));
    expect(result).toHaveLength(1);
    expect(result[0].position).toBe(1);
    expect(result[0].title).toBe('Sunrise');
  });

  it('CS11: addArtwork — creates collection_artworks row with computed position', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB); // owner_id=10
    mockPrisma.collection_artworks.aggregate.mockResolvedValueOnce({ _max: { position: 3 } });
    mockPrisma.collection_artworks.create.mockResolvedValueOnce({ id: 1, collection_id: BigInt(1), artwork_id: BigInt(7), position: 4 });
    const result = await svc.addArtwork('1', 10, 'buyer', 7);
    expect(mockPrisma.collection_artworks.create).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.collection_artworks.create.mock.calls[0][0] as any;
    expect(arg.data.artwork_id).toEqual(BigInt(7));
    expect(arg.data.position).toBe(4);
    expect(result.position).toBe(4);
  });

  it('CS12: removeArtwork — found: deletes row and returns { deleted: true }', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB); // owner_id=10
    mockPrisma.collection_artworks.findFirst.mockResolvedValueOnce({ id: 99, collection_id: BigInt(1), artwork_id: BigInt(7) });
    mockPrisma.collection_artworks.delete.mockResolvedValueOnce({});
    const result = await svc.removeArtwork('1', 10, 'buyer', 7);
    expect(mockPrisma.collection_artworks.delete).toHaveBeenCalledTimes(1);
    const arg = mockPrisma.collection_artworks.delete.mock.calls[0][0] as any;
    expect(arg.where.id).toBe(99);
    expect(result.deleted).toBe(true);
  });

  it('CS13: removeArtwork — not in collection: throws NotFoundException', async () => {
    mockPrisma.collections.findFirst.mockResolvedValueOnce(STUB);
    mockPrisma.collection_artworks.findFirst.mockResolvedValueOnce(null);
    await expect(svc.removeArtwork('1', 10, 'buyer', 999)).rejects.toThrow(NotFoundException);
  });
});
