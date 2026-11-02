/**
 * Phase 0.3U â€” ArtistService unit tests
 *
 * AS1  listArtists default pagination
 * AS2  listArtists custom pagination
 * AS3  getArtist by slug â€” found: returns profile
 * AS4  getArtist by slug â€” not found: throws NotFoundException
 * AS5  getArtist by id   â€” found: returns profile
 * AS6  getArtist â€” found but status not approved: throws NotFoundException
 * AS7  getArtistArtworks â€” delegates with pagination
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { ArtistService } from './artist.service';
import { PrismaService } from '../prisma.service';

const STUB_PROFILE = {
  id: BigInt(1), user_id: BigInt(10), display_name: 'Test Artist', slug: 'test-artist',
  bio: null, website: null, instagram_handle: null, status: 'approved',
  terms_accepted_at: null, deleted_at: null, created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  artist_profiles: { findMany: fn(), count: fn(), findUnique: fn() },
  artworks: { findMany: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” ArtistService', () => {
  let svc: ArtistService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        ArtistService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(ArtistService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('AS1: listArtists default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_PROFILE], 1]);
    const result = await svc.listArtists();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
    expect(result.meta.total).toBe(1);
  });

  it('AS2: listArtists custom pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listArtists({ page: 2, perPage: 5 });
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(5);
  });

  it('AS3: getArtist by slug â€” found and approved: returns profile', async () => {
    // non-numeric slug: byId assigned null without a DB call; one findUnique for slug
    mockPrisma.artist_profiles.findUnique.mockResolvedValueOnce(STUB_PROFILE);
    const result = await svc.getArtist('test-artist');
    expect(result.slug).toBe('test-artist');
  });

  it('AS4: getArtist by slug â€” not found: throws NotFoundException', async () => {
    // non-numeric slug: one findUnique call returns null
    mockPrisma.artist_profiles.findUnique.mockResolvedValueOnce(null);
    await expect(svc.getArtist('missing')).rejects.toThrow(NotFoundException);
  });

  it('AS5: getArtist by numeric id â€” found: returns profile', async () => {
    // numeric: one findUnique({ where: { id } }) returns profile
    mockPrisma.artist_profiles.findUnique.mockResolvedValueOnce(STUB_PROFILE);
    const result = await svc.getArtist('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('AS6: getArtist â€” found but not approved: throws NotFoundException', async () => {
    // non-numeric slug: one findUnique returns pending profile
    mockPrisma.artist_profiles.findUnique.mockResolvedValueOnce({ ...STUB_PROFILE, status: 'pending' });
    await expect(svc.getArtist('test-artist')).rejects.toThrow(NotFoundException);
  });

  it('AS7: getArtistArtworks â€” delegates with pagination', async () => {
    // getArtistArtworks calls getArtist(slug): one findUnique for slug lookup
    mockPrisma.artist_profiles.findUnique.mockResolvedValueOnce(STUB_PROFILE);
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.getArtistArtworks('test-artist', 2, 10);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(10);
  });
});
