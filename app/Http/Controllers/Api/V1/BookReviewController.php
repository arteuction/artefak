<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Library\ApproveBookReview;
use App\Domain\Library\SubmitBookReview;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookReview;
use App\Policies\BookReviewPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Book reviews — submit (authenticated), moderate (admin/curator).
 *
 * GET  /api/v1/books/{book}/reviews            — approved reviews (public)
 * POST /api/v1/books/{book}/reviews            — submit review (auth, purchased or free)
 * GET  /api/v1/books/{book}/reviews/pending    — pending queue (admin/curator)
 * PATCH /api/v1/books/{book}/reviews/{review}/approve — approve (admin/curator)
 * DELETE /api/v1/books/{book}/reviews/{review}        — reject/remove (admin/curator)
 */
final class BookReviewController extends Controller
{
    public function __construct(
        private readonly SubmitBookReview  $submit,
        private readonly ApproveBookReview $approve,
    ) {}

    /** GET /api/v1/books/{book}/reviews */
    public function index(Request $request, Book $book): JsonResponse
    {
        abort_if($book->status !== 'published', 404);

        $reviews = BookReview::where('book_id', $book->id)
            ->approved()
            ->with('user:id,name')
            ->orderByDesc('approved_at')
            ->paginate(20);

        return response()->json($reviews);
    }

    /** POST /api/v1/books/{book}/reviews */
    public function store(Request $request, Book $book): JsonResponse
    {
        abort_if($book->status !== 'published', 404);

        $validated = $request->validate([
            'rating'   => ['required', 'integer', 'min:1', 'max:5'],
            'headline' => ['nullable', 'string', 'max:160'],
            'body'     => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $review = $this->submit->execute(
                book:     $book,
                reviewer: $request->user(),
                rating:   (int) $validated['rating'],
                headline: $validated['headline'] ?? null,
                body:     $validated['body']     ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rating' => $e->getMessage()]);
        }

        return response()->json($review->load('user:id,name'), 201);
    }

    /** GET /api/v1/books/{book}/reviews/pending */
    public function pending(Request $request, Book $book): JsonResponse
    {
        $this->authorize('viewPending', BookReview::class);

        $reviews = BookReview::where('book_id', $book->id)
            ->pending()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json($reviews);
    }

    /** PATCH /api/v1/books/{book}/reviews/{review}/approve */
    public function approveReview(Request $request, Book $book, BookReview $review): JsonResponse
    {
        $this->authorize('moderate', BookReview::class);
        abort_if($review->book_id !== $book->id, 404);

        $approved = $this->approve->execute($review, $request->user());

        return response()->json($approved->load('approvedBy:id,name'));
    }

    /** DELETE /api/v1/books/{book}/reviews/{review} */
    public function destroy(Request $request, Book $book, BookReview $review): JsonResponse
    {
        $this->authorize('delete', $review);
        abort_if($review->book_id !== $book->id, 404);

        $this->approve->reject($review);

        return response()->json(['rejected' => true]);
    }
}
