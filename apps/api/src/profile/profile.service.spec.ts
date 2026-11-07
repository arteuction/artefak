/**
 * Phase 0.3U â€” ProfileService unit tests
 *
 * PR1   getGalleryOffers    â€” stub always returns empty paged response
 * PR2   getArtistProfile    â€” profile found: returns it
 * PR3   getArtistProfile    â€” profile not found: throws NotFoundException
 * PR4   createArtistProfile â€” delegates to prisma.create
 * PR5   updateArtistProfile â€” existing profile: delegates to prisma.update
 * PR6   updateArtistProfile â€” no existing profile: throws NotFoundException
 * PR7   getMyArtworks       â€” delegates with correct pagination
 * PR8   getWonItems         â€” delegates with correct pagination
 * PR9   getUserBids         â€” delegates with correct pagination
 * PR10  removeFromWatchlist â€” entry not found: throws NotFoundException
 * PR11  removeFromWatchlist â€” entry found: deletes and returns { deleted: true }
 * PR12  getWatchlist        â€” returns watchlist entries
 * PR13  addToWatchlist      â€” upserts and returns entry
 * PR14  getUserCollections  â€” returns paged public collections
 * PR15  getUserConsignments â€” returns paged consignments
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ProfileService } from './profile.service';
import { PrismaService } from '../prisma.service';

const STUB_PROFILE = {
  id: BigInt(1), display_name: 'Test Artist', slug: 'test-artist',
  bio: null, website: null, instagram_handle: null,
  status: 'active', terms_accepted_at: null, created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  artist_profiles: { findFirst: fn(), create: fn(), update: fn() },
  artworks: { findMany: fn(), count: fn() },
  auctionItem: { findMany: fn(), count: fn() },
  bid: { findMany: fn(), count: fn() },
  watchlist: { findFirst: fn(), delete: fn(), upsert: fn(), findMany: fn() },
  collections: { findMany: fn(), count: fn() },
  consignments: { findMany: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” ProfileService', () => {
  let svc: ProfileService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        ProfileService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(ProfileService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('PR1: getGalleryOffers â€” returns empty paged response', async () => {
    const result = await svc.getGalleryOffers(1);
    expect(result.data).toEqual([]);
    expect(result.meta.total).toBe(0);
  });

  it('PR2: getArtistProfile â€” found: returns profile', async () => {
    mockPrisma.artist_profiles.findFirst.mockResolvedValueOnce(STUB_PROFILE);
    const result = await svc.getArtistProfile(1);
    expect(result).toEqual(STUB_PROFILE);
    expect(mockPrisma.artist_profiles.findFirst).toHaveBeenCalledWith(
      expect.objectContaining({ where: expect.objectContaining({ user_id: BigInt(1) }) }),
    );
  });

  it('PR3: getArtistProfile â€” not found: throws NotFoundException', async () => {
    mockPrisma.artist_profiles.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getArtistProfile(999)).rejects.toThrow(NotFoundException);
  });

  it('PR4: createArtistProfile â€” delegates to prisma.create', async () => {
    mockPrisma.artist_profiles.create.mockResolvedValueOnce(STUB_PROFILE);
    const dto = { display_name: 'Test', slug: 'test', bio: 'Bio' };
    const result = await svc.createArtistProfile(1, dto);
    expect(result).toEqual(STUB_PROFILE);
    expect(mockPrisma.artist_profiles.create).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ display_name: 'Test', slug: 'test', status: 'pending' }) }),
    );
  });

  it('PR5: updateArtistProfile â€” existing profile: delegates to update', async () => {
    mockPrisma.artist_profiles.findFirst.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.artist_profiles.update.mockResolvedValueOnce({ ...STUB_PROFILE, bio: 'New bio' });
    const result = await svc.updateArtistProfile(1, { bio: 'New bio' });
    expect(result.bio).toBe('New bio');
    expect(mockPrisma.artist_profiles.update).toHaveBeenCalled();
  });

  it('PR6: updateArtistProfile â€” no existing profile: throws NotFoundException', async () => {
    mockPrisma.artist_profiles.findFirst.mockResolvedValueOnce(null);
    await expect(svc.updateArtistProfile(999, { bio: 'Bio' })).rejects.toThrow(NotFoundException);
  });

  it('PR7: getMyArtworks â€” delegates with correct pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.getMyArtworks(1, 2, 5);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(5);
    expect(result.meta.total).toBe(0);
  });

  it('PR8: getWonItems â€” delegates with correct pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.getWonItems(1, 1, 10);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(10);
  });

  it('PR9: getUserBids â€” delegates with correct pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.getUserBids(1, 3, 15);
    expect(result.meta.current_page).toBe(3);
    expect(result.meta.per_page).toBe(15);
  });

  it('PR10: removeFromWatchlist â€” entry not found: throws NotFoundException', async () => {
    mockPrisma.watchlist.findFirst.mockResolvedValueOnce(null);
    await expect(svc.removeFromWatchlist(1, 999)).rejects.toThrow(NotFoundException);
  });

  it('PR11: removeFromWatchlist â€” entry found: deletes and returns { deleted: true }', async () => {
    mockPrisma.watchlist.findFirst.mockResolvedValueOnce({ id: BigInt(1) });
    mockPrisma.watchlist.delete.mockResolvedValueOnce({});
    const result = await svc.removeFromWatchlist(1, 1);
    expect(result.deleted).toBe(true);
    expect(mockPrisma.watchlist.delete).toHaveBeenCalled();
  });

  it('PR12: getWatchlist — returns watchlist entries', async () => {
    mockPrisma.watchlist.findMany.mockResolvedValueOnce([{ id: BigInt(1), subject_type: 'artwork', subject_id: BigInt(5) }]);
    const result = await svc.getWatchlist(10);
    expect(result).toHaveLength(1);
    expect(result[0].subject_type).toBe('artwork');
  });

  it('PR13: addToWatchlist — upserts and returns entry', async () => {
    const entry = { id: BigInt(2), subject_type: 'auction', subject_id: BigInt(3), created_at: new Date() };
    mockPrisma.watchlist.upsert.mockResolvedValueOnce(entry);
    const result = await svc.addToWatchlist(10, 'auction', 3);
    expect(result.subject_type).toBe('auction');
    expect(mockPrisma.watchlist.upsert).toHaveBeenCalled();
  });

  it('PR14: getUserCollections — returns paged public collections', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[{ id: BigInt(1), title: 'My Coll', visibility: 'public' }], 1]);
    const result = await svc.getUserCollections(10);
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('PR15: getUserConsignments — returns paged consignments', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[{ id: BigInt(1), status: 'active' }], 1]);
    const result = await svc.getUserConsignments(10);
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
  });
});
