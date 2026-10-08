<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage authors on a book.
 * Book owner or admin/operator.
 */
final class BookAuthorController extends Controller
{
    private function gate(Request $request, Book $book): void
    {
        $user = $request->user();
        if ($book->owner_id !== $user->id && ! in_array($user->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/books/{book}/authors
     */
    public function index(Book $book): JsonResponse
    {
        $authors = $book->bookAuthors()->with('author:id,name,email')->get();
        return response()->json(['data' => $authors]);
    }

    /**
     * POST /api/v1/books/{book}/authors
     *
     * Add an author to the book with a royalty split share.
     * share_bps across all authors must not exceed 10000 (100%).
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);

        $data = $request->validate([
            'author_id'  => ['required', 'integer', 'exists:users,id'],
            'share_bps'  => ['required', 'integer', 'min:1', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        // Prevent duplicate
        if ($book->bookAuthors()->where('author_id', $data['author_id'])->exists()) {
            abort(422, 'This author is already listed on the book.');
        }

        // Validate total split does not exceed 10000 bps
        $existingTotal = $book->bookAuthors()->sum('share_bps');
        if ($existingTotal + $data['share_bps'] > 10000) {
            abort(422, "Adding {$data['share_bps']} bps would exceed 100% total split.");
        }

        $author = BookAuthor::create([
            'book_id'    => $book->id,
            'author_id'  => $data['author_id'],
            'share_bps'  => $data['share_bps'],
            'sort_order' => $data['sort_order'] ?? ($book->bookAuthors()->count() + 1),
        ]);

        return response()->json($author->load('author:id,name'), 201);
    }

    /**
     * PATCH /api/v1/books/{book}/authors/{bookAuthor}
     *
     * Update share_bps or sort_order for an existing author.
     */
    public function update(Request $request, Book $book, BookAuthor $bookAuthor): JsonResponse
    {
        $this->gate($request, $book);
        abort_if($bookAuthor->book_id !== $book->id, 404);

        $data = $request->validate([
            'share_bps'  => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (isset($data['share_bps'])) {
            $otherTotal = $book->bookAuthors()->where('id', '!=', $bookAuthor->id)->sum('share_bps');
            if ($otherTotal + $data['share_bps'] > 10000) {
                abort(422, "New share_bps would exceed 100% total split.");
            }
        }

        $bookAuthor->update($data);

        return response()->json($bookAuthor->fresh());
    }

    /**
     * DELETE /api/v1/books/{book}/authors/{bookAuthor}
     */
    public function destroy(Request $request, Book $book, BookAuthor $bookAuthor): JsonResponse
    {
        $this->gate($request, $book);
        abort_if($bookAuthor->book_id !== $book->id, 404);

        $bookAuthor->delete();

        return response()->json(null, 204);
    }
}
