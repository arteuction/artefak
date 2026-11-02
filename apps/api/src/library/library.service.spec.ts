/**
 * Phase 0.3V — LibraryService unit tests
 *
 * LS1  listBooks default — returns paged result with dto mapping
 * LS2  listBooks with query filter
 * LS3  getBook — found: returns book
 * LS4  getBook — not found: returns null
 * LS5  myBooks — returns paged entitlements
 * LS6  getEntitlement — has_access true when entitlement found
 * LS7  getEntitlement — has_access false when no entitlement
 * LS8  purchaseBook — returns pending status
 * LS9  getBookReviews — returns empty paged stub
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { LibraryService } from './library.service';
import { PrismaService } from '../prisma.service';

const STUB_BOOK = {
  id: BigInt(1), title: 'Art History', slug: 'art-history', description: null,
  short_description: null, isbn: null, publisher: null, language: 'bg',
  page_count: 200, publication_year: 2024, price_cents: BigInt(2000),
  currency: 'BGN', is_free: false, is_featured: false, status: 'published',
  created_at: new Date(), updated_at: new Date(),
  authors: [],
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  book: { findMany: fn(), findFirst: fn(), count: fn() },
  book_entitlements: { findMany: fn(), findFirst: fn(), count: fn() },
  $transaction: fn(),
  $executeRaw: fn(),
};

describe('Phase 0.3V — LibraryService', () => {
  let svc: LibraryService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [LibraryService, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    svc = module.get(LibraryService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('LS1: listBooks default — returns paged result', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_BOOK], 1]);
    const result = await svc.listBooks();
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
    expect(result.data[0].slug).toBe('art-history');
  });

  it('LS2: listBooks with query — carries filter', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 0]);
    const result = await svc.listBooks({ q: 'sculpture' });
    expect(result.meta.total).toBe(0);
  });

  it('LS3: getBook — found: returns book', async () => {
    mockPrisma.book.findFirst.mockResolvedValueOnce(STUB_BOOK);
    const result = await svc.getBook('art-history');
    expect(result?.slug).toBe('art-history');
  });

  it('LS4: getBook — not found: returns null', async () => {
    mockPrisma.book.findFirst.mockResolvedValueOnce(null);
    const result = await svc.getBook('missing');
    expect(result).toBeNull();
  });

  it('LS5: myBooks — returns paged entitlements', async () => {
    const ENTITLEMENT = { id: BigInt(1), user_id: BigInt(10), revoked_at: null, granted_at: new Date(), books: STUB_BOOK };
    mockPrisma.$transaction.mockResolvedValueOnce([[ENTITLEMENT], 1]);
    const result = await svc.myBooks(10);
    expect(result.data).toHaveLength(1);
    expect(result.meta.total).toBe(1);
  });

  it('LS6: getEntitlement — has_access true when found', async () => {
    mockPrisma.book_entitlements.findFirst.mockResolvedValueOnce({ id: BigInt(1), source: 'purchase', revoked_at: null });
    const result = await svc.getEntitlement('art-history', 10);
    expect(result.has_access).toBe(true);
    expect(result.source).toBe('purchase');
  });

  it('LS7: getEntitlement — has_access false when not found', async () => {
    mockPrisma.book_entitlements.findFirst.mockResolvedValueOnce(null);
    const result = await svc.getEntitlement('missing', 10);
    expect(result.has_access).toBe(false);
    expect(result.source).toBeNull();
  });

  it('LS8: purchaseBook — returns pending status', async () => {
    const result = await svc.purchaseBook('art-history', 10);
    expect(result.status).toBe('pending');
  });

  it('LS9: getBookReviews — returns empty paged stub', async () => {
    const result = await svc.getBookReviews('art-history');
    expect(result.data).toEqual([]);
    expect(result.meta.total).toBe(0);
  });
});
