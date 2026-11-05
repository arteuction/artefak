/**
 * Phase 0.3U â€” AuthorizationService unit tests (pure policy logic)
 *
 * Tests synchronous policy methods without DB. DB-dependent methods use
 * a Prisma mock that returns the minimal shape needed.
 *
 * AZ1   adminArea          â€” admin: true, operator: true, buyer: false
 * AZ2   artLotGalleryTransition â€” staff: true, buyer: false
 * AZ3   artistApplicationReview â€” staff: true, artist: false
 * AZ4   artworkCreate      â€” artist/admin/operator: true, buyer: false
 * AZ5   artworkSdgClaimReview â€” admin: true, operator: false
 * AZ6   bookCategoryCreate  â€” admin/operator/curator: true, buyer: false
 * AZ7   bookCategoryUpdate  â€” same set as create
 * AZ8   bookCategoryDelete  â€” same set as create
 * AZ9   bookPublish         â€” staff: true, artist: false
 * AZ10  bookGrantEntitlement â€” staff: true, buyer: false
 * AZ11  bookReviewModerate  â€” admin/operator/curator: true, buyer: false
 * AZ12  bookReviewViewPending â€” same as moderate
 * AZ13  disputeViewAny      â€” staff: true, artist: false
 * AZ14  disputeAssign       â€” staff: true, buyer: false
 * AZ15  disputeResolve      â€” staff: true, buyer: false
 * AZ16  domainEventViewAny  â€” admin: true, operator: false
 * AZ17  galleryCreate       â€” staff: true, buyer: false
 * AZ18  enforce             â€” passes when allowed, throws ForbiddenException when denied
 * AZ19  artLotUpdate        â€” consignor: true, other buyer: false, staff: true
 * AZ20  artworkView         â€” owner: true, other buyer: false, staff: true
 * AZ21  bookManageContent   â€” book owner: true, staff: true, other: false
 * AZ22  disputeView         â€” dispute opener: true, staff: true, other: false
 * AZ23  bookReviewDelete    â€” moderator: true, reviewer own pending: true, reviewer approved: false
 * AZ24  galleryUpdate       â€” staff: true, gallery admin: true, non-member: false
 * AZ25  sellNowOfferCounter â€” not found: throws NotFoundException
 * AZ26  sellNowOfferActOnOffer â€” not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { ForbiddenException, NotFoundException } from '@nestjs/common';
import { AuthorizationService } from './authorization.service';
import { PrismaService } from '../prisma.service';
import { AuthenticatedUser } from './auth.types';

const makeUser = (id: number, role: AuthenticatedUser['role']): AuthenticatedUser => ({
  id,
  role,
  email: `user${id}@test.com`,
  emailVerifiedAt: new Date(),
});

const admin = makeUser(1, 'admin');
const operator = makeUser(2, 'operator');
const curator = makeUser(3, 'curator');
const artist = makeUser(4, 'artist');
const buyer = makeUser(5, 'buyer');

const fn = () => jest.fn() as any;

const mockPrisma = {
  art_lots: { findUnique: fn() },
  artworks: { findUnique: fn() },
  artwork_sdg_claims: { findUnique: fn() },
  book: { findUnique: fn() },
  book_reviews: { findUnique: fn() },
  disputes: { findUnique: fn() },
  gallery_staff: { findFirst: fn() },
  sell_now_offers: { findUnique: fn() },
};

describe('Phase 0.3U â€” AuthorizationService', () => {
  let svc: AuthorizationService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        AuthorizationService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(AuthorizationService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  // â”€â”€ adminArea (AdminPolicy) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ1a: adminArea â€” admin returns true', () => { expect(svc.adminArea(admin)).toBe(true); });
  it('AZ1b: adminArea â€” operator returns true', () => { expect(svc.adminArea(operator)).toBe(true); });
  it('AZ1c: adminArea â€” buyer returns false', () => { expect(svc.adminArea(buyer)).toBe(false); });
  it('AZ1d: adminArea â€” artist returns false', () => { expect(svc.adminArea(artist)).toBe(false); });

  // â”€â”€ artLotGalleryTransition â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ2a: artLotGalleryTransition â€” admin: true', () => { expect(svc.artLotGalleryTransition(admin)).toBe(true); });
  it('AZ2b: artLotGalleryTransition â€” buyer: false', () => { expect(svc.artLotGalleryTransition(buyer)).toBe(false); });

  // â”€â”€ artistApplicationReview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ3a: artistApplicationReview â€” admin: true', () => { expect(svc.artistApplicationReview(admin)).toBe(true); });
  it('AZ3b: artistApplicationReview â€” artist: false', () => { expect(svc.artistApplicationReview(artist)).toBe(false); });

  // â”€â”€ artworkCreate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ4a: artworkCreate â€” artist: true', () => { expect(svc.artworkCreate(artist)).toBe(true); });
  it('AZ4b: artworkCreate â€” admin: true', () => { expect(svc.artworkCreate(admin)).toBe(true); });
  it('AZ4c: artworkCreate â€” buyer: false', () => { expect(svc.artworkCreate(buyer)).toBe(false); });

  // â”€â”€ artworkSdgClaimReview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ5a: artworkSdgClaimReview â€” admin: true', () => { expect(svc.artworkSdgClaimReview(admin)).toBe(true); });
  it('AZ5b: artworkSdgClaimReview â€” operator: false (admin-only)', () => { expect(svc.artworkSdgClaimReview(operator)).toBe(false); });

  // â”€â”€ bookCategory policies â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ6: bookCategoryCreate â€” admin/operator/curator: true, buyer: false', () => {
    expect(svc.bookCategoryCreate(admin)).toBe(true);
    expect(svc.bookCategoryCreate(operator)).toBe(true);
    expect(svc.bookCategoryCreate(curator)).toBe(true);
    expect(svc.bookCategoryCreate(buyer)).toBe(false);
  });

  it('AZ7: bookCategoryUpdate â€” same as create', () => {
    expect(svc.bookCategoryUpdate(curator)).toBe(true);
    expect(svc.bookCategoryUpdate(artist)).toBe(false);
  });

  it('AZ8: bookCategoryDelete â€” same as create', () => {
    expect(svc.bookCategoryDelete(operator)).toBe(true);
    expect(svc.bookCategoryDelete(buyer)).toBe(false);
  });

  // â”€â”€ bookPublish â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ9a: bookPublish â€” admin: true, artist: false', () => {
    expect(svc.bookPublish(admin)).toBe(true);
    expect(svc.bookPublish(artist)).toBe(false);
  });

  // â”€â”€ bookGrantEntitlement â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ10: bookGrantEntitlement â€” admin: true, buyer: false', () => {
    expect(svc.bookGrantEntitlement(admin)).toBe(true);
    expect(svc.bookGrantEntitlement(buyer)).toBe(false);
  });

  // â”€â”€ bookReviewModerate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ11: bookReviewModerate â€” moderators: true, buyer: false', () => {
    expect(svc.bookReviewModerate(admin)).toBe(true);
    expect(svc.bookReviewModerate(operator)).toBe(true);
    expect(svc.bookReviewModerate(curator)).toBe(true);
    expect(svc.bookReviewModerate(buyer)).toBe(false);
  });

  it('AZ12: bookReviewViewPending â€” same as moderate', () => {
    expect(svc.bookReviewViewPending(curator)).toBe(true);
    expect(svc.bookReviewViewPending(artist)).toBe(false);
  });

  // â”€â”€ disputeViewAny â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ13: disputeViewAny â€” admin/operator: true, artist: false', () => {
    expect(svc.disputeViewAny(admin)).toBe(true);
    expect(svc.disputeViewAny(operator)).toBe(true);
    expect(svc.disputeViewAny(artist)).toBe(false);
  });

  it('AZ14: disputeAssign â€” staff: true, buyer: false', () => {
    expect(svc.disputeAssign(admin)).toBe(true);
    expect(svc.disputeAssign(buyer)).toBe(false);
  });

  it('AZ15: disputeResolve â€” staff: true, buyer: false', () => {
    expect(svc.disputeResolve(operator)).toBe(true);
    expect(svc.disputeResolve(buyer)).toBe(false);
  });

  // â”€â”€ domainEventViewAny â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ16a: domainEventViewAny â€” admin: true', () => { expect(svc.domainEventViewAny(admin)).toBe(true); });
  it('AZ16b: domainEventViewAny â€” operator: false (admin-only)', () => { expect(svc.domainEventViewAny(operator)).toBe(false); });

  // â”€â”€ galleryCreate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ17: galleryCreate â€” staff: true, buyer: false', () => {
    expect(svc.galleryCreate(admin)).toBe(true);
    expect(svc.galleryCreate(buyer)).toBe(false);
  });

  // â”€â”€ enforce helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ18a: enforce â€” passes when allowed', async () => {
    await expect(svc.enforce(Promise.resolve(true))).resolves.toBeUndefined();
  });

  it('AZ18b: enforce â€” throws ForbiddenException when denied', async () => {
    await expect(svc.enforce(Promise.resolve(false))).rejects.toThrow(ForbiddenException);
  });

  it('AZ18c: enforce â€” works with synchronous boolean true', async () => {
    await expect(svc.enforce(true)).resolves.toBeUndefined();
  });

  it('AZ18d: enforce â€” works with synchronous boolean false', async () => {
    await expect(svc.enforce(false, 'No access')).rejects.toThrow(ForbiddenException);
  });

  // â”€â”€ artLotUpdate (DB-dependent) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ19a: artLotUpdate â€” consignor: true', async () => {
    mockPrisma.art_lots.findUnique.mockResolvedValueOnce({ consignor_id: BigInt(buyer.id) });
    await expect(svc.artLotUpdate(buyer, 1)).resolves.toBe(true);
  });

  it('AZ19b: artLotUpdate â€” other buyer: false', async () => {
    mockPrisma.art_lots.findUnique.mockResolvedValueOnce({ consignor_id: BigInt(99) });
    await expect(svc.artLotUpdate(buyer, 1)).resolves.toBe(false);
  });

  it('AZ19c: artLotUpdate â€” staff always: true', async () => {
    mockPrisma.art_lots.findUnique.mockResolvedValueOnce({ consignor_id: BigInt(99) });
    await expect(svc.artLotUpdate(admin, 1)).resolves.toBe(true);
  });

  it('AZ19d: artLotUpdate â€” lot not found: throws NotFoundException', async () => {
    mockPrisma.art_lots.findUnique.mockResolvedValueOnce(null);
    await expect(svc.artLotUpdate(buyer, 999)).rejects.toThrow(NotFoundException);
  });

  // â”€â”€ artworkView (DB-dependent) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ20a: artworkView â€” owner: true', async () => {
    mockPrisma.artworks.findUnique.mockResolvedValueOnce({ user_id: BigInt(artist.id) });
    await expect(svc.artworkView(artist, 1)).resolves.toBe(true);
  });

  it('AZ20b: artworkView â€” other buyer: false', async () => {
    mockPrisma.artworks.findUnique.mockResolvedValueOnce({ user_id: BigInt(artist.id) });
    await expect(svc.artworkView(buyer, 1)).resolves.toBe(false);
  });

  it('AZ20c: artworkView â€” staff: true', async () => {
    mockPrisma.artworks.findUnique.mockResolvedValueOnce({ user_id: BigInt(artist.id) });
    await expect(svc.artworkView(admin, 1)).resolves.toBe(true);
  });

  it('AZ20d: artworkView â€” not found: throws NotFoundException', async () => {
    mockPrisma.artworks.findUnique.mockResolvedValueOnce(null);
    await expect(svc.artworkView(artist, 999)).rejects.toThrow(NotFoundException);
  });

  // â”€â”€ bookManageContent (DB-dependent) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ21a: bookManageContent â€” book owner: true', async () => {
    mockPrisma.book.findUnique.mockResolvedValueOnce({ owner_id: BigInt(artist.id) });
    await expect(svc.bookManageContent(artist, 1)).resolves.toBe(true);
  });

  it('AZ21b: bookManageContent â€” staff: true', async () => {
    mockPrisma.book.findUnique.mockResolvedValueOnce({ owner_id: BigInt(artist.id) });
    await expect(svc.bookManageContent(admin, 1)).resolves.toBe(true);
  });

  it('AZ21c: bookManageContent â€” other user: false', async () => {
    mockPrisma.book.findUnique.mockResolvedValueOnce({ owner_id: BigInt(artist.id) });
    await expect(svc.bookManageContent(buyer, 1)).resolves.toBe(false);
  });

  it('AZ21d: bookManageContent â€” book not found: throws NotFoundException', async () => {
    mockPrisma.book.findUnique.mockResolvedValueOnce(null);
    await expect(svc.bookManageContent(artist, 999)).rejects.toThrow(NotFoundException);
  });

  // â”€â”€ disputeView (DB-dependent) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ22a: disputeView â€” opener: true', async () => {
    mockPrisma.disputes.findUnique.mockResolvedValueOnce({ opened_by: BigInt(buyer.id) });
    await expect(svc.disputeView(buyer, 1)).resolves.toBe(true);
  });

  it('AZ22b: disputeView â€” staff: true', async () => {
    mockPrisma.disputes.findUnique.mockResolvedValueOnce({ opened_by: BigInt(buyer.id) });
    await expect(svc.disputeView(admin, 1)).resolves.toBe(true);
  });

  it('AZ22c: disputeView â€” other user: false', async () => {
    mockPrisma.disputes.findUnique.mockResolvedValueOnce({ opened_by: BigInt(99) });
    await expect(svc.disputeView(buyer, 1)).resolves.toBe(false);
  });

  it('AZ22d: disputeView â€” not found: throws NotFoundException', async () => {
    mockPrisma.disputes.findUnique.mockResolvedValueOnce(null);
    await expect(svc.disputeView(buyer, 999)).rejects.toThrow(NotFoundException);
  });

  // â”€â”€ bookReviewDelete (DB-dependent) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ23a: bookReviewDelete â€” moderator: always true (no DB call needed)', async () => {
    await expect(svc.bookReviewDelete(curator, 1)).resolves.toBe(true);
    // DB should not be called for moderator
    expect(mockPrisma.book_reviews.findUnique).not.toHaveBeenCalled();
  });

  it('AZ23b: bookReviewDelete â€” reviewer own pending review: true', async () => {
    mockPrisma.book_reviews.findUnique.mockResolvedValueOnce({ user_id: BigInt(buyer.id), is_approved: false });
    await expect(svc.bookReviewDelete(buyer, 1)).resolves.toBe(true);
  });

  it('AZ23c: bookReviewDelete â€” reviewer own approved review: false', async () => {
    mockPrisma.book_reviews.findUnique.mockResolvedValueOnce({ user_id: BigInt(buyer.id), is_approved: true });
    await expect(svc.bookReviewDelete(buyer, 1)).resolves.toBe(false);
  });

  it('AZ23d: bookReviewDelete â€” other user: false', async () => {
    mockPrisma.book_reviews.findUnique.mockResolvedValueOnce({ user_id: BigInt(99), is_approved: false });
    await expect(svc.bookReviewDelete(buyer, 1)).resolves.toBe(false);
  });

  it('AZ23e: bookReviewDelete â€” review not found: throws NotFoundException', async () => {
    mockPrisma.book_reviews.findUnique.mockResolvedValueOnce(null);
    await expect(svc.bookReviewDelete(buyer, 999)).rejects.toThrow(NotFoundException);
  });

  // â”€â”€ galleryUpdate (DB-dependent) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  it('AZ24a: galleryUpdate â€” staff: true (no DB call)', async () => {
    await expect(svc.galleryUpdate(admin, 1)).resolves.toBe(true);
    expect(mockPrisma.gallery_staff.findFirst).not.toHaveBeenCalled();
  });

  it('AZ24b: galleryUpdate â€” gallery admin member: true', async () => {
    mockPrisma.gallery_staff.findFirst.mockResolvedValueOnce({ gallery_id: 1, user_id: buyer.id, role: 'admin' });
    await expect(svc.galleryUpdate(buyer, 1)).resolves.toBe(true);
  });

  it('AZ24c: galleryUpdate â€” non-member: false', async () => {
    mockPrisma.gallery_staff.findFirst.mockResolvedValueOnce(null);
    await expect(svc.galleryUpdate(buyer, 1)).resolves.toBe(false);
  });

  it('AZ25: sellNowOfferCounter â€” offer not found: throws NotFoundException', async () => {
    mockPrisma.sell_now_offers.findUnique.mockResolvedValueOnce(null);
    await expect(svc.sellNowOfferCounter(buyer, 999)).rejects.toThrow(NotFoundException);
  });

  it('AZ26: sellNowOfferActOnOffer â€” offer not found: throws NotFoundException', async () => {
    mockPrisma.sell_now_offers.findUnique.mockResolvedValueOnce(null);
    await expect(svc.sellNowOfferActOnOffer(buyer, 999)).rejects.toThrow(NotFoundException);
  });
});
