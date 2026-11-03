/**
 * Phase 0.3U â€” SearchService unit tests
 *
 * SS1  searchArtworks empty query â€” includes all non-deleted
 * SS2  searchArtworks with query  â€” includes query in meta
 * SS3  searchArtworks pagination  â€” correct meta
 * SS4  getArtwork by slug â€” found: returns artwork
 * SS5  getArtwork by id â€” found: returns artwork
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { SearchService } from './search.service';
import { PrismaService } from '../prisma.service';
import { MeilisearchService } from './meilisearch.service';

const fn = () => jest.fn() as any;

const mockPrisma = {
  artworks: { findMany: fn(), count: fn(), findFirst: fn() },
  $transaction: fn(),
};

const mockMeili = { enabled: false, searchArtworks: jest.fn() as any };

describe('Phase 0.3U â€” SearchService', () => {
  let svc: SearchService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        SearchService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: MeilisearchService, useValue: mockMeili },
      ],
    }).compile();
    svc = module.get(SearchService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('SS1: searchArtworks empty query â€” returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.searchArtworks('');
    expect(result).toHaveProperty('data');
    expect(result).toHaveProperty('meta');
    expect(result.meta.query).toBe('');
    expect(result.meta.total).toBe(0);
  });

  it('SS2: searchArtworks with query â€” carries query in meta', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.searchArtworks('oil painting');
    expect(result.meta.query).toBe('oil painting');
  });

  it('SS3: searchArtworks custom pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 30]);
    const result = await svc.searchArtworks('art', 2, 10);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(10);
    expect(result.meta.last_page).toBe(3);
  });

  it('SS4: getArtwork by slug — found: returns artwork', async () => {
    const stub = { id: BigInt(1), title: 'Sunrise', slug: 'sunrise', medium: 'oil', year_created: 2020, status: 'published', created_at: new Date() };
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(stub);
    const result = await svc.getArtwork('sunrise');
    expect(mockPrisma.artworks.findFirst).toHaveBeenCalledTimes(1);
    const call = mockPrisma.artworks.findFirst.mock.calls[0][0] as any;
    expect(call.where).toMatchObject({ slug: 'sunrise', deleted_at: null });
    expect(result?.slug).toBe('sunrise');
  });

  it('SS5: getArtwork by numeric id — queries by id', async () => {
    const stub = { id: BigInt(5), title: 'Dusk', slug: 'dusk', medium: null, year_created: null, status: 'published', created_at: new Date() };
    mockPrisma.artworks.findFirst.mockResolvedValueOnce(stub);
    const result = await svc.getArtwork('5');
    const call = mockPrisma.artworks.findFirst.mock.calls[0][0] as any;
    expect(call.where).toMatchObject({ id: BigInt(5), deleted_at: null });
    expect(result?.id).toEqual(BigInt(5));
  });

  it('SS6: searchArtworks — uses Meilisearch when enabled', async () => {
    mockMeili.enabled = true;
    mockMeili.searchArtworks.mockResolvedValueOnce({ hits: [{ id: 1, title: 'Art', slug: 'art', medium: 'oil', year_created: 2020, status: 'published' }], estimatedTotalHits: 1 });
    const result = await svc.searchArtworks('oil', 1, 10);
    expect(mockMeili.searchArtworks).toHaveBeenCalledWith('oil', 10, 0);
    expect(result.data[0].title).toBe('Art');
    expect(result.meta.total).toBe(1);
    mockMeili.enabled = false;
  });
});
