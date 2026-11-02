/**
 * Phase 0.3U â€” SearchService unit tests
 *
 * SS1  searchArtworks empty query â€” includes all non-deleted
 * SS2  searchArtworks with query  â€” includes query in meta
 * SS3  searchArtworks pagination  â€” correct meta
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { SearchService } from './search.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const mockPrisma = {
  artworks: { findMany: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” SearchService', () => {
  let svc: SearchService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [SearchService, { provide: PrismaService, useValue: mockPrisma }],
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
});
