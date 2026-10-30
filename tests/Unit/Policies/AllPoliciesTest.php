<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\ArtistApplication;
use App\Models\ArtLot;
use App\Models\Artwork;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookReview;
use App\Models\Dispute;
use App\Models\Gallery;
use App\Models\GalleryStaff;
use App\Models\SellNowOffer;
use App\Models\User;
use App\Models\ArtworkSdgClaim;
use App\Policies\AdminPolicy;
use App\Policies\ArtistApplicationPolicy;
use App\Policies\ArtLotPolicy;
use App\Policies\ArtworkPolicy;
use App\Policies\ArtworkSdgClaimPolicy;
use App\Policies\BookCategoryPolicy;
use App\Policies\BookPolicy;
use App\Policies\BookReviewPolicy;
use App\Policies\DisputePolicy;
use App\Policies\DomainEventPolicy;
use App\Policies\GalleryPolicy;
use App\Policies\SellNowOfferPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct unit tests for every Policy class introduced in phase 180.
 *
 * Tests policy logic in isolation for every relevant role / ownership
 * combination without going through HTTP routing.
 */
final class AllPoliciesTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function artwork(User $owner): Artwork
    {
        return Artwork::create([
            'user_id'    => $owner->id,
            'title'      => 'T ' . uniqid(),
            'slug'       => 'slug-' . uniqid(),
            'status'     => 'draft',
            'is_original' => true,
        ]);
    }

    private function gallery(): Gallery
    {
        return Gallery::create([
            'name'   => 'G ' . uniqid(),
            'slug'   => 'g-' . uniqid(),
            'status' => 'active',
            'type'   => 'private',
        ]);
    }

    private function lot(User $consignor): ArtLot
    {
        $art = $this->artwork($consignor);
        $gal = $this->gallery();

        return ArtLot::create([
            'artwork_id'    => $art->id,
            'gallery_id'    => $gal->id,
            'consignor_id'  => $consignor->id,
            'sale_mode'     => 'sell_now',
            'status'        => 'active',
        ]);
    }

    private function offer(User $buyer, ArtLot $lot): SellNowOffer
    {
        return SellNowOffer::create([
            'art_lot_id'   => $lot->id,
            'buyer_id'     => $buyer->id,
            'amount_cents' => 10000,
            'currency'     => 'EUR',
            'status'       => 'pending',
        ]);
    }

    private function dispute(User $opener): Dispute
    {
        return Dispute::create([
            'type'        => 'payment',
            'description' => 'desc',
            'opened_by'   => $opener->id,
            'status'      => 'open',
        ]);
    }

    private function bookReview(User $reviewer, bool $approved): BookReview
    {
        $book = Book::create([
            'owner_id'    => $reviewer->id,
            'title'       => 'B ' . uniqid(),
            'slug'        => 'b-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);

        return BookReview::create([
            'book_id'     => $book->id,
            'user_id'     => $reviewer->id,
            'rating'      => 4,
            'is_approved' => $approved,
        ]);
    }

    private function bookCategory(): BookCategory
    {
        return BookCategory::create([
            'name'      => 'Cat ' . uniqid(),
            'slug'      => 'cat-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function artistApplication(): ArtistApplication
    {
        $artist  = $this->user('artist');
        $profile = \App\Models\ArtistProfile::create([
            'user_id' => $artist->id,
            'bio'     => 'bio',
            'slug'    => 'profile-' . uniqid(),
            'status'  => 'pending',
        ]);

        return ArtistApplication::create([
            'artist_profile_id' => $profile->id,
            'status'            => 'pending',
            'motivation'        => 'I make art.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AdminPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_admin_policy_admin_area_allows_admin(): void
    {
        $this->assertTrue((new AdminPolicy())->adminArea($this->user('admin')));
    }

    public function test_admin_policy_admin_area_allows_operator(): void
    {
        $this->assertTrue((new AdminPolicy())->adminArea($this->user('operator')));
    }

    public function test_admin_policy_admin_area_denies_artist(): void
    {
        $this->assertFalse((new AdminPolicy())->adminArea($this->user('artist')));
    }

    public function test_admin_policy_admin_area_denies_buyer(): void
    {
        $this->assertFalse((new AdminPolicy())->adminArea($this->user('buyer')));
    }

    public function test_admin_policy_admin_area_denies_curator(): void
    {
        $this->assertFalse((new AdminPolicy())->adminArea($this->user('curator')));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ArtworkPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_artwork_policy_create_allows_artist(): void
    {
        $this->assertTrue((new ArtworkPolicy())->create($this->user('artist')));
    }

    public function test_artwork_policy_create_allows_admin(): void
    {
        $this->assertTrue((new ArtworkPolicy())->create($this->user('admin')));
    }

    public function test_artwork_policy_create_allows_operator(): void
    {
        $this->assertTrue((new ArtworkPolicy())->create($this->user('operator')));
    }

    public function test_artwork_policy_create_denies_buyer(): void
    {
        $this->assertFalse((new ArtworkPolicy())->create($this->user('buyer')));
    }

    public function test_artwork_policy_view_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->view($owner, $artwork));
    }

    public function test_artwork_policy_view_allows_admin(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->view($this->user('admin'), $artwork));
    }

    public function test_artwork_policy_view_denies_other_artist(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertFalse((new ArtworkPolicy())->view($this->user('artist'), $artwork));
    }

    public function test_artwork_policy_update_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->update($owner, $artwork));
    }

    public function test_artwork_policy_update_denies_other_artist(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertFalse((new ArtworkPolicy())->update($this->user('artist'), $artwork));
    }

    public function test_artwork_policy_upload_image_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->uploadImage($owner, $artwork));
    }

    public function test_artwork_policy_upload_image_denies_buyer(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertFalse((new ArtworkPolicy())->uploadImage($this->user('buyer'), $artwork));
    }

    public function test_artwork_policy_create_lot_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->createLot($owner, $artwork));
    }

    public function test_artwork_policy_create_lot_denies_other(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertFalse((new ArtworkPolicy())->createLot($this->user('buyer'), $artwork));
    }

    public function test_artwork_policy_publish_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->publish($owner, $artwork));
    }

    public function test_artwork_policy_publish_allows_admin(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->publish($this->user('admin'), $artwork));
    }

    public function test_artwork_policy_publish_denies_buyer(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertFalse((new ArtworkPolicy())->publish($this->user('buyer'), $artwork));
    }

    public function test_artwork_policy_manage_revision_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->manageRevision($owner, $artwork));
    }

    public function test_artwork_policy_confirm_upload_allows_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertTrue((new ArtworkPolicy())->confirmUpload($owner, $artwork));
    }

    public function test_artwork_policy_confirm_upload_denies_stranger(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);

        $this->assertFalse((new ArtworkPolicy())->confirmUpload($this->user('buyer'), $artwork));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ArtLotPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_art_lot_policy_update_allows_consignor(): void
    {
        $consignor = $this->user('artist');

        $this->assertTrue((new ArtLotPolicy())->update($consignor, $this->lot($consignor)));
    }

    public function test_art_lot_policy_update_allows_admin(): void
    {
        $consignor = $this->user('artist');

        $this->assertTrue((new ArtLotPolicy())->update($this->user('admin'), $this->lot($consignor)));
    }

    public function test_art_lot_policy_update_allows_operator(): void
    {
        $consignor = $this->user('artist');

        $this->assertTrue((new ArtLotPolicy())->update($this->user('operator'), $this->lot($consignor)));
    }

    public function test_art_lot_policy_update_denies_buyer(): void
    {
        $consignor = $this->user('artist');

        $this->assertFalse((new ArtLotPolicy())->update($this->user('buyer'), $this->lot($consignor)));
    }

    public function test_art_lot_policy_update_denies_other_artist(): void
    {
        $consignor = $this->user('artist');

        $this->assertFalse((new ArtLotPolicy())->update($this->user('artist'), $this->lot($consignor)));
    }

    public function test_art_lot_policy_transition_allows_consignor(): void
    {
        $consignor = $this->user('artist');

        $this->assertTrue((new ArtLotPolicy())->transition($consignor, $this->lot($consignor)));
    }

    public function test_art_lot_policy_transition_denies_buyer(): void
    {
        $consignor = $this->user('artist');

        $this->assertFalse((new ArtLotPolicy())->transition($this->user('buyer'), $this->lot($consignor)));
    }

    public function test_art_lot_policy_gallery_transition_allows_admin(): void
    {
        $this->assertTrue((new ArtLotPolicy())->galleryTransition($this->user('admin')));
    }

    public function test_art_lot_policy_gallery_transition_allows_operator(): void
    {
        $this->assertTrue((new ArtLotPolicy())->galleryTransition($this->user('operator')));
    }

    public function test_art_lot_policy_gallery_transition_denies_artist(): void
    {
        $this->assertFalse((new ArtLotPolicy())->galleryTransition($this->user('artist')));
    }

    public function test_art_lot_policy_gallery_transition_denies_buyer(): void
    {
        $this->assertFalse((new ArtLotPolicy())->galleryTransition($this->user('buyer')));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GalleryPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_gallery_policy_create_allows_admin(): void
    {
        $this->assertTrue((new GalleryPolicy())->create($this->user('admin')));
    }

    public function test_gallery_policy_create_allows_operator(): void
    {
        $this->assertTrue((new GalleryPolicy())->create($this->user('operator')));
    }

    public function test_gallery_policy_create_denies_artist(): void
    {
        $this->assertFalse((new GalleryPolicy())->create($this->user('artist')));
    }

    public function test_gallery_policy_create_denies_buyer(): void
    {
        $this->assertFalse((new GalleryPolicy())->create($this->user('buyer')));
    }

    public function test_gallery_policy_update_allows_platform_admin(): void
    {
        $this->assertTrue((new GalleryPolicy())->update($this->user('admin'), $this->gallery()));
    }

    public function test_gallery_policy_update_allows_gallery_admin_staff(): void
    {
        $gallery = $this->gallery();
        $manager = $this->user('artist');

        GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $manager->id,
            'role'        => 'admin',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        $this->assertTrue((new GalleryPolicy())->update($manager, $gallery));
    }

    public function test_gallery_policy_update_denies_non_staff_buyer(): void
    {
        $this->assertFalse((new GalleryPolicy())->update($this->user('buyer'), $this->gallery()));
    }

    public function test_gallery_policy_manage_staff_allows_admin(): void
    {
        $this->assertTrue((new GalleryPolicy())->manageStaff($this->user('admin'), $this->gallery()));
    }

    public function test_gallery_policy_manage_staff_denies_buyer(): void
    {
        $this->assertFalse((new GalleryPolicy())->manageStaff($this->user('buyer'), $this->gallery()));
    }

    public function test_gallery_policy_manage_exhibition_allows_any_gallery_staff(): void
    {
        $gallery = $this->gallery();
        $staff   = $this->user('artist');

        GalleryStaff::create([
            'gallery_id'  => $gallery->id,
            'user_id'     => $staff->id,
            'role'        => 'staff',
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        $this->assertTrue((new GalleryPolicy())->manageExhibition($staff, $gallery));
    }

    public function test_gallery_policy_manage_exhibition_denies_outsider(): void
    {
        $this->assertFalse((new GalleryPolicy())->manageExhibition($this->user('buyer'), $this->gallery()));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SellNowOfferPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_sell_now_offer_policy_counter_allows_consignor(): void
    {
        $consignor = $this->user('artist');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($this->user('buyer'), $lot);

        $this->assertTrue((new SellNowOfferPolicy())->counter($consignor, $offer));
    }

    public function test_sell_now_offer_policy_counter_allows_admin(): void
    {
        $consignor = $this->user('artist');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($this->user('buyer'), $lot);

        $this->assertTrue((new SellNowOfferPolicy())->counter($this->user('admin'), $offer));
    }

    public function test_sell_now_offer_policy_counter_denies_buyer(): void
    {
        $consignor = $this->user('artist');
        $buyer     = $this->user('buyer');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($buyer, $lot);

        $this->assertFalse((new SellNowOfferPolicy())->counter($buyer, $offer));
    }

    public function test_sell_now_offer_policy_counter_denies_unrelated_artist(): void
    {
        $consignor = $this->user('artist');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($this->user('buyer'), $lot);

        $this->assertFalse((new SellNowOfferPolicy())->counter($this->user('artist'), $offer));
    }

    public function test_sell_now_offer_policy_act_on_offer_allows_buyer(): void
    {
        $consignor = $this->user('artist');
        $buyer     = $this->user('buyer');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($buyer, $lot);

        $this->assertTrue((new SellNowOfferPolicy())->actOnOffer($buyer, $offer));
    }

    public function test_sell_now_offer_policy_act_on_offer_allows_consignor(): void
    {
        $consignor = $this->user('artist');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($this->user('buyer'), $lot);

        $this->assertTrue((new SellNowOfferPolicy())->actOnOffer($consignor, $offer));
    }

    public function test_sell_now_offer_policy_act_on_offer_allows_operator(): void
    {
        $consignor = $this->user('artist');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($this->user('buyer'), $lot);

        $this->assertTrue((new SellNowOfferPolicy())->actOnOffer($this->user('operator'), $offer));
    }

    public function test_sell_now_offer_policy_act_on_offer_denies_unrelated_user(): void
    {
        $consignor = $this->user('artist');
        $lot       = $this->lot($consignor);
        $offer     = $this->offer($this->user('buyer'), $lot);

        $this->assertFalse((new SellNowOfferPolicy())->actOnOffer($this->user('buyer'), $offer));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DisputePolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_dispute_policy_view_any_allows_admin(): void
    {
        $this->assertTrue((new DisputePolicy())->viewAny($this->user('admin')));
    }

    public function test_dispute_policy_view_any_allows_operator(): void
    {
        $this->assertTrue((new DisputePolicy())->viewAny($this->user('operator')));
    }

    public function test_dispute_policy_view_any_denies_buyer(): void
    {
        $this->assertFalse((new DisputePolicy())->viewAny($this->user('buyer')));
    }

    public function test_dispute_policy_view_any_denies_artist(): void
    {
        $this->assertFalse((new DisputePolicy())->viewAny($this->user('artist')));
    }

    public function test_dispute_policy_view_allows_opener(): void
    {
        $opener  = $this->user('buyer');
        $dispute = $this->dispute($opener);

        $this->assertTrue((new DisputePolicy())->view($opener, $dispute));
    }

    public function test_dispute_policy_view_allows_admin(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertTrue((new DisputePolicy())->view($this->user('admin'), $dispute));
    }

    public function test_dispute_policy_view_denies_other_user(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertFalse((new DisputePolicy())->view($this->user('buyer'), $dispute));
    }

    public function test_dispute_policy_assign_allows_admin(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertTrue((new DisputePolicy())->assign($this->user('admin'), $dispute));
    }

    public function test_dispute_policy_assign_allows_operator(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertTrue((new DisputePolicy())->assign($this->user('operator'), $dispute));
    }

    public function test_dispute_policy_assign_denies_opener(): void
    {
        $opener  = $this->user('buyer');
        $dispute = $this->dispute($opener);

        $this->assertFalse((new DisputePolicy())->assign($opener, $dispute));
    }

    public function test_dispute_policy_assign_denies_artist(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertFalse((new DisputePolicy())->assign($this->user('artist'), $dispute));
    }

    public function test_dispute_policy_resolve_allows_admin(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertTrue((new DisputePolicy())->resolve($this->user('admin'), $dispute));
    }

    public function test_dispute_policy_resolve_allows_operator(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertTrue((new DisputePolicy())->resolve($this->user('operator'), $dispute));
    }

    public function test_dispute_policy_resolve_denies_opener(): void
    {
        $opener  = $this->user('buyer');
        $dispute = $this->dispute($opener);

        $this->assertFalse((new DisputePolicy())->resolve($opener, $dispute));
    }

    public function test_dispute_policy_attach_evidence_allows_opener(): void
    {
        $opener  = $this->user('buyer');
        $dispute = $this->dispute($opener);

        $this->assertTrue((new DisputePolicy())->attachEvidence($opener, $dispute));
    }

    public function test_dispute_policy_attach_evidence_allows_admin(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertTrue((new DisputePolicy())->attachEvidence($this->user('admin'), $dispute));
    }

    public function test_dispute_policy_attach_evidence_denies_other_buyer(): void
    {
        $dispute = $this->dispute($this->user('buyer'));

        $this->assertFalse((new DisputePolicy())->attachEvidence($this->user('buyer'), $dispute));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BookReviewPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_book_review_policy_moderate_allows_admin(): void
    {
        $this->assertTrue((new BookReviewPolicy())->moderate($this->user('admin')));
    }

    public function test_book_review_policy_moderate_allows_curator(): void
    {
        $this->assertTrue((new BookReviewPolicy())->moderate($this->user('curator')));
    }

    public function test_book_review_policy_moderate_allows_operator(): void
    {
        $this->assertTrue((new BookReviewPolicy())->moderate($this->user('operator')));
    }

    public function test_book_review_policy_moderate_denies_artist(): void
    {
        $this->assertFalse((new BookReviewPolicy())->moderate($this->user('artist')));
    }

    public function test_book_review_policy_moderate_denies_buyer(): void
    {
        $this->assertFalse((new BookReviewPolicy())->moderate($this->user('buyer')));
    }

    public function test_book_review_policy_delete_allows_moderator_regardless_of_approval(): void
    {
        $reviewer = $this->user('buyer');
        $review   = $this->bookReview($reviewer, true);

        $this->assertTrue((new BookReviewPolicy())->delete($this->user('admin'), $review));
    }

    public function test_book_review_policy_delete_allows_curator_to_delete_approved_review(): void
    {
        $reviewer = $this->user('buyer');
        $review   = $this->bookReview($reviewer, true);

        $this->assertTrue((new BookReviewPolicy())->delete($this->user('curator'), $review));
    }

    public function test_book_review_policy_delete_allows_owner_of_pending_review(): void
    {
        $reviewer = $this->user('buyer');
        $review   = $this->bookReview($reviewer, false);

        $this->assertTrue((new BookReviewPolicy())->delete($reviewer, $review));
    }

    public function test_book_review_policy_delete_denies_owner_of_approved_review(): void
    {
        $reviewer = $this->user('buyer');
        $review   = $this->bookReview($reviewer, true);

        $this->assertFalse((new BookReviewPolicy())->delete($reviewer, $review));
    }

    public function test_book_review_policy_delete_denies_other_user_for_pending_review(): void
    {
        $review = $this->bookReview($this->user('buyer'), false);

        $this->assertFalse((new BookReviewPolicy())->delete($this->user('buyer'), $review));
    }

    public function test_book_review_policy_view_pending_allows_moderators(): void
    {
        $policy = new BookReviewPolicy();

        $this->assertTrue($policy->viewPending($this->user('admin')));
        $this->assertTrue($policy->viewPending($this->user('operator')));
        $this->assertTrue($policy->viewPending($this->user('curator')));
    }

    public function test_book_review_policy_view_pending_denies_buyer(): void
    {
        $this->assertFalse((new BookReviewPolicy())->viewPending($this->user('buyer')));
    }

    public function test_book_review_policy_view_pending_denies_artist(): void
    {
        $this->assertFalse((new BookReviewPolicy())->viewPending($this->user('artist')));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BookCategoryPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_book_category_policy_create_allows_admin(): void
    {
        $this->assertTrue((new BookCategoryPolicy())->create($this->user('admin')));
    }

    public function test_book_category_policy_create_allows_operator(): void
    {
        $this->assertTrue((new BookCategoryPolicy())->create($this->user('operator')));
    }

    public function test_book_category_policy_create_denies_buyer(): void
    {
        $this->assertFalse((new BookCategoryPolicy())->create($this->user('buyer')));
    }

    public function test_book_category_policy_create_denies_artist(): void
    {
        $this->assertFalse((new BookCategoryPolicy())->create($this->user('artist')));
    }

    public function test_book_category_policy_create_denies_curator(): void
    {
        $this->assertFalse((new BookCategoryPolicy())->create($this->user('curator')));
    }

    public function test_book_category_policy_update_allows_admin(): void
    {
        $this->assertTrue((new BookCategoryPolicy())->update($this->user('admin'), $this->bookCategory()));
    }

    public function test_book_category_policy_update_allows_operator(): void
    {
        $this->assertTrue((new BookCategoryPolicy())->update($this->user('operator'), $this->bookCategory()));
    }

    public function test_book_category_policy_update_denies_artist(): void
    {
        $this->assertFalse((new BookCategoryPolicy())->update($this->user('artist'), $this->bookCategory()));
    }

    public function test_book_category_policy_delete_allows_admin(): void
    {
        $this->assertTrue((new BookCategoryPolicy())->delete($this->user('admin'), $this->bookCategory()));
    }

    public function test_book_category_policy_delete_denies_buyer(): void
    {
        $this->assertFalse((new BookCategoryPolicy())->delete($this->user('buyer'), $this->bookCategory()));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ArtistApplicationPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_artist_application_policy_review_allows_admin(): void
    {
        $this->assertTrue((new ArtistApplicationPolicy())->review($this->user('admin'), $this->artistApplication()));
    }

    public function test_artist_application_policy_review_allows_operator(): void
    {
        $this->assertTrue((new ArtistApplicationPolicy())->review($this->user('operator'), $this->artistApplication()));
    }

    public function test_artist_application_policy_review_denies_artist(): void
    {
        $this->assertFalse((new ArtistApplicationPolicy())->review($this->user('artist'), $this->artistApplication()));
    }

    public function test_artist_application_policy_review_denies_buyer(): void
    {
        $this->assertFalse((new ArtistApplicationPolicy())->review($this->user('buyer'), $this->artistApplication()));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BookPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_book_policy_publish_allows_admin(): void
    {
        $this->assertTrue((new BookPolicy())->publish($this->user('admin')));
    }

    public function test_book_policy_publish_allows_operator(): void
    {
        $this->assertTrue((new BookPolicy())->publish($this->user('operator')));
    }

    public function test_book_policy_publish_denies_artist(): void
    {
        $this->assertFalse((new BookPolicy())->publish($this->user('artist')));
    }

    public function test_book_policy_publish_denies_buyer(): void
    {
        $this->assertFalse((new BookPolicy())->publish($this->user('buyer')));
    }

    public function test_book_policy_manage_content_allows_owner(): void
    {
        $owner = $this->user('artist');
        $book  = Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'B ' . uniqid(),
            'slug'        => 'b-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'draft',
        ]);

        $this->assertTrue((new BookPolicy())->manageContent($owner, $book));
    }

    public function test_book_policy_manage_content_allows_admin(): void
    {
        $owner = $this->user('artist');
        $book  = Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'B2 ' . uniqid(),
            'slug'        => 'b2-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'draft',
        ]);

        $this->assertTrue((new BookPolicy())->manageContent($this->user('admin'), $book));
    }

    public function test_book_policy_manage_content_denies_buyer(): void
    {
        $owner = $this->user('artist');
        $book  = Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'B3 ' . uniqid(),
            'slug'        => 'b3-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'draft',
        ]);

        $this->assertFalse((new BookPolicy())->manageContent($this->user('buyer'), $book));
    }

    public function test_book_policy_grant_entitlement_allows_staff_only(): void
    {
        $policy = new BookPolicy();

        $this->assertTrue($policy->grantEntitlement($this->user('admin')));
        $this->assertTrue($policy->grantEntitlement($this->user('operator')));
        $this->assertFalse($policy->grantEntitlement($this->user('buyer')));
        $this->assertFalse($policy->grantEntitlement($this->user('artist')));
    }

    public function test_book_policy_revoke_entitlement_allows_staff_only(): void
    {
        $policy = new BookPolicy();

        $this->assertTrue($policy->revokeEntitlement($this->user('admin')));
        $this->assertTrue($policy->revokeEntitlement($this->user('operator')));
        $this->assertFalse($policy->revokeEntitlement($this->user('buyer')));
        $this->assertFalse($policy->revokeEntitlement($this->user('curator')));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ArtworkSdgClaimPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_sdg_claim_policy_submit_allows_artwork_owner(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);
        $claim   = ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 4,
            'rationale'  => 'Education.',
            'status'     => 'pending',
        ]);

        $this->assertTrue((new ArtworkSdgClaimPolicy())->submit($owner, $claim));
    }

    public function test_sdg_claim_policy_submit_denies_other_artist(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);
        $claim   = ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 4,
            'rationale'  => 'Education.',
            'status'     => 'pending',
        ]);

        $this->assertFalse((new ArtworkSdgClaimPolicy())->submit($this->user('artist'), $claim));
    }

    public function test_sdg_claim_policy_review_allows_admin_only(): void
    {
        $owner   = $this->user('artist');
        $artwork = $this->artwork($owner);
        $claim   = ArtworkSdgClaim::create([
            'artwork_id' => $artwork->id,
            'sdg_number' => 5,
            'rationale'  => 'Gender equality.',
            'status'     => 'pending',
        ]);
        $policy = new ArtworkSdgClaimPolicy();

        $this->assertTrue($policy->review($this->user('admin'), $claim));
        $this->assertFalse($policy->review($this->user('operator'), $claim));
        $this->assertFalse($policy->review($this->user('artist'), $claim));
        $this->assertFalse($policy->review($this->user('buyer'), $claim));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DomainEventPolicy
    // ═══════════════════════════════════════════════════════════════════════

    public function test_domain_event_policy_view_any_allows_only_admin(): void
    {
        $policy = new DomainEventPolicy();

        $this->assertTrue($policy->viewAny($this->user('admin')));
        $this->assertFalse($policy->viewAny($this->user('operator')));
        $this->assertFalse($policy->viewAny($this->user('buyer')));
        $this->assertFalse($policy->viewAny($this->user('artist')));
        $this->assertFalse($policy->viewAny($this->user('curator')));
    }
}
