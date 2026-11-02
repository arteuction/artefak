/**
 * Authorization service â€” NestJS equivalent of Laravel's 12 Policy classes.
 *
 * Each method maps 1:1 to a Laravel Policy method. The exact permission logic
 * is preserved from the original PHP policies; no simplification or broadening.
 *
 * Policy sources:
 *   AdminPolicy, ArtLotPolicy, ArtistApplicationPolicy, ArtworkPolicy,
 *   ArtworkSdgClaimPolicy, BookCategoryPolicy, BookPolicy, BookReviewPolicy,
 *   DisputePolicy, DomainEventPolicy, GalleryPolicy, SellNowOfferPolicy
 */

import { Injectable, ForbiddenException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { AuthenticatedUser, UserRole } from './auth.types';

const STAFF: UserRole[] = ['admin', 'operator'];
const BOOK_MODERATORS: UserRole[] = ['admin', 'operator', 'curator'];

@Injectable()
export class AuthorizationService {
  constructor(private readonly prisma: PrismaService) {}

  private isStaff(user: AuthenticatedUser): boolean {
    return STAFF.includes(user.role);
  }

  // â”€â”€ AdminPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Gate: 'admin-area' â€” admin and operator only */
  adminArea(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  // â”€â”€ ArtLotPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Update lot metadata â€” consignor or staff */
  async artLotUpdate(user: AuthenticatedUser, lotId: number): Promise<boolean> {
    const lot = await this.prisma.art_lots.findUnique({
      where: { id: lotId },
      select: { consignor_id: true },
    });
    if (!lot) throw new NotFoundException('ArtLot not found');
    return Number(lot.consignor_id) === user.id || this.isStaff(user);
  }

  /** State-machine transition â€” consignor or staff */
  async artLotTransition(user: AuthenticatedUser, lotId: number): Promise<boolean> {
    return this.artLotUpdate(user, lotId);
  }

  /** Gallery-specific transitions â€” staff only */
  artLotGalleryTransition(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  // â”€â”€ ArtistApplicationPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Review artist application â€” staff only */
  artistApplicationReview(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  // â”€â”€ ArtworkPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** View non-published artwork â€” owner or staff */
  async artworkView(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    const artwork = await this.prisma.artworks.findUnique({
      where: { id: artworkId },
      select: { user_id: true },
    });
    if (!artwork) throw new NotFoundException('Artwork not found');
    return Number(artwork.user_id) === user.id || this.isStaff(user);
  }

  /** Edit artwork metadata â€” owner or staff */
  async artworkUpdate(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    return this.artworkView(user, artworkId);
  }

  /** Create artwork â€” artist, admin, operator */
  artworkCreate(user: AuthenticatedUser): boolean {
    return ['artist', ...STAFF].includes(user.role);
  }

  /** Manage revision â€” owner or staff */
  async artworkManageRevision(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    return this.artworkView(user, artworkId);
  }

  /** Request upload URL â€” owner or staff */
  async artworkUploadImage(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    return this.artworkView(user, artworkId);
  }

  /** Confirm upload â€” owner or staff */
  async artworkConfirmUpload(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    return this.artworkView(user, artworkId);
  }

  /** Publish artwork â€” owner or staff */
  async artworkPublish(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    return this.artworkView(user, artworkId);
  }

  /** Create lot from artwork â€” owner or staff */
  async artworkCreateLot(user: AuthenticatedUser, artworkId: number): Promise<boolean> {
    return this.artworkView(user, artworkId);
  }

  // â”€â”€ ArtworkSdgClaimPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Submit SDG claim â€” artist may only claim their own artworks */
  async artworkSdgClaimSubmit(user: AuthenticatedUser, claimId: number): Promise<boolean> {
    const claim = await this.prisma.artwork_sdg_claims.findUnique({
      where: { id: claimId },
      include: { artworks: { select: { user_id: true } } },
    });
    if (!claim) throw new NotFoundException('SDG claim not found');
    return Number(claim.artworks.user_id) === user.id;
  }

  /** Review SDG claim â€” admin only */
  artworkSdgClaimReview(user: AuthenticatedUser): boolean {
    return user.role === 'admin';
  }

  // â”€â”€ BookCategoryPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  bookCategoryCreate(user: AuthenticatedUser): boolean {
    return BOOK_MODERATORS.includes(user.role);
  }

  bookCategoryUpdate(user: AuthenticatedUser): boolean {
    return BOOK_MODERATORS.includes(user.role);
  }

  bookCategoryDelete(user: AuthenticatedUser): boolean {
    return BOOK_MODERATORS.includes(user.role);
  }

  // â”€â”€ BookPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Publish/unpublish book â€” staff only */
  bookPublish(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  /** Manage authors/files â€” book owner or staff */
  async bookManageContent(user: AuthenticatedUser, bookId: number): Promise<boolean> {
    const book = await this.prisma.book.findUnique({
      where: { id: bookId },
      select: { owner_id: true },
    });
    if (!book) throw new NotFoundException('Book not found');
    return Number(book.owner_id) === user.id || this.isStaff(user);
  }

  /** Grant entitlement â€” staff only */
  bookGrantEntitlement(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  /** Revoke entitlement â€” staff only */
  bookRevokeEntitlement(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  // â”€â”€ BookReviewPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Approve/reject any review â€” moderators */
  bookReviewModerate(user: AuthenticatedUser): boolean {
    return BOOK_MODERATORS.includes(user.role);
  }

  /**
   * Delete a review:
   *   - moderators: any review
   *   - reviewer: own pending (not-yet-approved) review only
   */
  async bookReviewDelete(user: AuthenticatedUser, reviewId: number): Promise<boolean> {
    if (BOOK_MODERATORS.includes(user.role)) return true;

    const review = await this.prisma.book_reviews.findUnique({
      where: { id: reviewId },
      select: { user_id: true, is_approved: true },
    });
    if (!review) throw new NotFoundException('Review not found');

    return Number(review.user_id) === user.id && !review.is_approved;
  }

  /** View pending reviews â€” moderators */
  bookReviewViewPending(user: AuthenticatedUser): boolean {
    return BOOK_MODERATORS.includes(user.role);
  }

  // â”€â”€ DisputePolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** List all disputes â€” staff only */
  disputeViewAny(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  /** View specific dispute â€” opener or staff */
  async disputeView(user: AuthenticatedUser, disputeId: number): Promise<boolean> {
    const dispute = await this.prisma.disputes.findUnique({
      where: { id: disputeId },
      select: { opened_by: true },
    });
    if (!dispute) throw new NotFoundException('Dispute not found');
    return Number(dispute.opened_by) === user.id || this.isStaff(user);
  }

  /** Assign dispute â€” staff only */
  disputeAssign(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  /** Resolve dispute â€” staff only */
  disputeResolve(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  /** Attach evidence â€” opener or staff */
  async disputeAttachEvidence(user: AuthenticatedUser, disputeId: number): Promise<boolean> {
    return this.disputeView(user, disputeId);
  }

  // â”€â”€ DomainEventPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** View domain events â€” admin only */
  domainEventViewAny(user: AuthenticatedUser): boolean {
    return user.role === 'admin';
  }

  // â”€â”€ GalleryPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Create gallery â€” staff only */
  galleryCreate(user: AuthenticatedUser): boolean {
    return this.isStaff(user);
  }

  /** Update gallery metadata â€” gallery admin or platform staff */
  async galleryUpdate(user: AuthenticatedUser, galleryId: number): Promise<boolean> {
    if (this.isStaff(user)) return true;
    return this.isGalleryAdmin(user.id, galleryId);
  }

  /** Manage gallery staff roster â€” gallery admin or platform staff */
  async galleryManageStaff(user: AuthenticatedUser, galleryId: number): Promise<boolean> {
    if (this.isStaff(user)) return true;
    return this.isGalleryAdmin(user.id, galleryId);
  }

  /** Manage exhibitions â€” any gallery staff or platform staff */
  async galleryManageExhibition(user: AuthenticatedUser, galleryId: number): Promise<boolean> {
    if (this.isStaff(user)) return true;
    const membership = await this.prisma.gallery_staff.findFirst({
      where: { gallery_id: galleryId, user_id: user.id },
    });
    return membership !== null;
  }

  private async isGalleryAdmin(userId: number, galleryId: number): Promise<boolean> {
    const membership = await this.prisma.gallery_staff.findFirst({
      where: { gallery_id: galleryId, user_id: userId, role: 'admin' },
    });
    return membership !== null;
  }

  // â”€â”€ SellNowOfferPolicy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Counter an offer â€” consignor or staff */
  async sellNowOfferCounter(user: AuthenticatedUser, offerId: number): Promise<boolean> {
    const offer = await this.prisma.sell_now_offers.findUnique({
      where: { id: offerId },
      include: { art_lots: { select: { consignor_id: true } } },
    });
    if (!offer) throw new NotFoundException('SellNowOffer not found');
    return Number(offer.art_lots.consignor_id) === user.id || this.isStaff(user);
  }

  /** Accept/reject an offer â€” buyer, consignor, or staff */
  async sellNowOfferActOnOffer(user: AuthenticatedUser, offerId: number): Promise<boolean> {
    const offer = await this.prisma.sell_now_offers.findUnique({
      where: { id: offerId },
      include: { art_lots: { select: { consignor_id: true } } },
    });
    if (!offer) throw new NotFoundException('SellNowOffer not found');
    const isParty = Number(offer.buyer_id) === user.id || Number(offer.art_lots.consignor_id) === user.id;
    return isParty || this.isStaff(user);
  }

  // â”€â”€ Enforcement helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  /** Throw ForbiddenException if the check fails */
  async enforce(check: Promise<boolean> | boolean, message = 'Forbidden'): Promise<void> {
    const allowed = await check;
    if (!allowed) throw new ForbiddenException(message);
  }
}

