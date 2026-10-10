<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Library\GrantBookEntitlement;
use App\Domain\Library\RevokeBookEntitlement;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookEntitlement;
use App\Models\BookPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * Library — public book catalogue, purchase initiation, entitlement management.
 *
 * Payment finalisation (paid → entitlement) is handled by the Stripe webhook
 * (HandleStripeWebhook → FinalizePaidBookPurchase), not here.
 */
final class LibraryController extends Controller
{
    /**
     * GET /api/v1/books
     *
     * Public catalogue. Published books only.
     * Filterable by is_free, language, featured.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Book::with(['bookAuthors.author:id,name'])
            ->published()
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at');

        if ($request->boolean('is_free')) {
            $query->where('is_free', true);
        }

        if ($request->filled('language')) {
            $query->where('language', $request->input('language'));
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * GET /api/v1/books/{book}
     *
     * Public book detail — includes authors, file version metadata.
     * Does not expose download URLs (those require an entitlement check).
     */
    public function show(Request $request, Book $book): JsonResponse
    {
        abort_if($book->status !== 'published', 404);

        $book->load([
            'bookAuthors.author:id,name',
            'files:id,book_id,version,type,size_bytes,created_at',
        ]);

        $data = $book->toArray();

        $user = $request->user();
        if ($user) {
            if ($book->is_free) {
                $data['access'] = 'free';
            } else {
                $hasEntitlement = \App\Models\BookEntitlement::where('book_id', $book->id)
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->exists();
                $data['access'] = $hasEntitlement ? 'purchased' : 'none';
            }
        } else {
            $data['access'] = 'none';
        }

        return response()->json($data);
    }

    /**
     * POST /api/v1/books/{book}/purchase
     *
     * Initiates a book purchase. Creates a BookPurchase in 'pending' status.
     * The client is expected to complete payment via Stripe (Checkout or PaymentIntent).
     * On payment success the webhook calls FinalizePaidBookPurchase.
     *
     * Free books are granted immediately via GrantBookEntitlement.
     */
    public function purchase(Request $request, Book $book): JsonResponse
    {
        abort_if($book->status !== 'published', 404);

        $user = $request->user();

        // Free book — grant immediately, no purchase record needed
        if ($book->is_free) {
            $entitlement = (new GrantBookEntitlement())->forFreeBook($user, $book);
            return response()->json(['entitlement' => $entitlement], 200);
        }

        if (! $book->isPurchasable()) {
            abort(422, 'This book is not currently available for purchase.');
        }

        // Idempotency: return existing pending purchase if it exists
        $existing = BookPurchase::where('buyer_id', $user->id)
            ->where('book_id', $book->id)
            ->whereIn('status', ['pending', 'paid'])
            ->first();

        if ($existing && $existing->status === 'paid') {
            return response()->json(['purchase' => $existing, 'already_paid' => true], 200);
        }

        if ($existing) {
            return response()->json(['purchase' => $existing], 200);
        }

        $purchase = BookPurchase::create([
            'buyer_id'         => $user->id,
            'book_id'          => $book->id,
            'status'           => 'pending',
            'price_cents'      => $book->price_cents,
            'currency'         => $book->currency ?? 'EUR',
            'gross_cents'      => $book->price_cents,
            'tax_cents'        => 0,
            'fee_cents'        => 0,
            'split_base_cents' => $book->price_cents,
            'profile_key'      => 'book_default',
            'profile_version'  => 1,
            'author_bps'       => 7000,
            'fund_bps'         => 1500,
            'ops_bps'          => 1500,
            'idempotency_key'  => (string) Str::uuid(),
        ]);

        return response()->json(['purchase' => $purchase], 201);
    }

    /**
     * POST /api/v1/books/{book}/grant
     *
     * Admin-only: grant a book entitlement to a user directly.
     */
    public function grant(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $target      = \App\Models\User::findOrFail((int) $data['user_id']);
        $entitlement = (new GrantBookEntitlement())->forAdmin($target, $book);

        return response()->json($entitlement, 201);
    }

    /**
     * GET /api/v1/books/{book}/entitlement
     *
     * Returns the authenticated user's entitlement for this book (if any).
     */
    public function entitlement(Request $request, Book $book): JsonResponse
    {
        $entitlement = BookEntitlement::where('user_id', $request->user()->id)
            ->where('book_id', $book->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $entitlement) {
            return response()->json(['entitlement' => null], 200);
        }

        return response()->json(['entitlement' => $entitlement]);
    }

    /**
     * DELETE /api/v1/book-entitlements/{entitlement}
     *
     * Admin-only: revoke a book entitlement.
     */
    public function revokeEntitlement(Request $request, BookEntitlement $bookEntitlement): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        (new RevokeBookEntitlement())->forAdmin($bookEntitlement);

        return response()->json(['message' => 'Entitlement revoked.']);
    }

    /**
     * PATCH /api/v1/books/{book}/publish
     *
     * Admin publishes a book (draft → published).
     */
    public function publish(Request $request, Book $book): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        if ($book->status === 'published') {
            return response()->json(['message' => 'Book is already published.', 'book' => $book]);
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $book->update([
            'status'      => 'published',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        return response()->json(['message' => 'Book published.', 'book' => $book->fresh()]);
    }

    /**
     * PATCH /api/v1/books/{book}/unpublish
     *
     * Admin unpublishes a book (published → draft).
     */
    public function unpublish(Request $request, Book $book): JsonResponse
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }

        if ($book->status !== 'published') {
            abort(422, 'Only published books can be unpublished.');
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $book->update([
            'status'      => 'draft',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        return response()->json(['message' => 'Book unpublished.', 'book' => $book->fresh()]);
    }

    /**
     * GET /api/v1/my-books
     *
     * Returns all active book entitlements for the authenticated user.
     */
    public function myBooks(Request $request): JsonResponse
    {
        $entitlements = BookEntitlement::with(['book:id,title,slug,description,is_free,price_cents,currency'])
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->orderByDesc('granted_at')
            ->paginate(20);

        return response()->json($entitlements);
    }

    /**
     * POST /api/v1/books/{book}/checkout-session
     *
     * Creates (or retrieves) a Stripe Checkout Session for a pending book purchase.
     * Idempotent: re-uses an open session if one already exists.
     */
    /**
     * Create a Stripe Checkout Session for a book purchase.
     *
     * Idempotent: re-uses an open session if one already exists.
     * Returns `{already_purchased: true}` when the user already owns the book.
     *
     * @response 200 {"url": "https://checkout.stripe.com/pay/cs_test_..."}
     * @response 200 {"already_purchased": true}
     * @response 422 {"message": "This book is free — no payment required."}
     */
    public function checkoutSession(Request $request, Book $book): JsonResponse
    {
        abort_if($book->status !== 'published', 404);
        abort_if($book->is_free, 422, 'This book is free — no payment required.');

        $user = $request->user();

        // Check existing entitlement
        $hasEntitlement = BookEntitlement::where('book_id', $book->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists();

        if ($hasEntitlement) {
            return response()->json(['already_purchased' => true], 200);
        }

        // Find or create a pending purchase
        $purchase = BookPurchase::where('buyer_id', $user->id)
            ->where('book_id', $book->id)
            ->where('status', 'pending')
            ->first();

        if (! $purchase) {
            $purchase = BookPurchase::create([
                'buyer_id'         => $user->id,
                'book_id'          => $book->id,
                'status'           => 'pending',
                'price_cents'      => $book->price_cents,
                'currency'         => $book->currency ?? 'EUR',
                'gross_cents'      => $book->price_cents,
                'tax_cents'        => 0,
                'fee_cents'        => 0,
                'split_base_cents' => $book->price_cents,
                'profile_key'      => 'book_default',
                'profile_version'  => 1,
                'author_bps'       => 7000,
                'fund_bps'         => 1500,
                'ops_bps'          => 1500,
                'idempotency_key'  => (string) Str::uuid(),
            ]);
        }

        // Idempotency: reuse existing open session
        if ($purchase->stripe_checkout_session_id !== null) {
            $stripe  = new StripeClient(config('services.stripe.secret'));
            $session = $stripe->checkout->sessions->retrieve($purchase->stripe_checkout_session_id);
            if ($session->status === 'open') {
                return response()->json(['url' => $session->url]);
            }
        }

        $stripe  = new StripeClient(config('services.stripe.secret'));
        $appUrl  = rtrim((string) config('app.url'), '/');

        $session = $stripe->checkout->sessions->create([
            'mode'           => 'payment',
            'currency'       => strtolower($purchase->currency),
            'line_items'     => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower($purchase->currency),
                    'unit_amount'  => (int) $purchase->price_cents,
                    'product_data' => ['name' => $book->title],
                ],
            ]],
            'payment_intent_data' => [
                'metadata' => [
                    'book_purchase_id' => (string) $purchase->id,
                    'book_id'          => (string) $book->id,
                ],
            ],
            'metadata' => [
                'book_purchase_id' => (string) $purchase->id,
            ],
            'success_url' => "{$appUrl}/library/{$book->slug}?payment=success",
            'cancel_url'  => "{$appUrl}/library/{$book->slug}?payment=cancelled",
        ]);

        $purchase->update([
            'stripe_checkout_session_id' => $session->id,
        ]);

        return response()->json(['url' => $session->url]);
    }
}
