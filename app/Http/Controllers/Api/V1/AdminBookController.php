<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin book management — create and update books on behalf of any owner.
 *
 * Publish/unpublish lives in LibraryController (PATCH /books/{book}/publish).
 */
final class AdminBookController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'operator'], true)) {
            abort(403);
        }
    }

    /**
     * POST /api/v1/admin/books
     *
     * Create a book on behalf of any user (owner_id required).
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'owner_id'          => ['required', 'integer', 'exists:users,id'],
            'title'             => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'isbn'              => ['nullable', 'string', 'max:20'],
            'publisher'         => ['nullable', 'string', 'max:200'],
            'language'          => ['nullable', 'string', 'max:10'],
            'edition'           => ['nullable', 'string', 'max:50'],
            'page_count'        => ['nullable', 'integer', 'min:1'],
            'publication_year'  => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'country_origin'    => ['nullable', 'string', 'max:3'],
            'price_cents'       => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:3'],
            'is_free'           => ['boolean'],
            'preview_pages'     => ['nullable', 'integer', 'min:0'],
        ]);

        $book = Book::create([
            ...$data,
            'slug'     => Str::slug($data['title']) . '-' . time(),
            'status'   => 'draft',
            'is_free'  => $data['is_free'] ?? false,
            'currency' => $data['currency'] ?? 'BGN',
        ]);

        return response()->json(['data' => $book], 201);
    }

    /**
     * PATCH /api/v1/admin/books/{book}
     *
     * Update any book field (admin only, any status).
     */
    public function update(Request $request, Book $book): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'title'             => ['sometimes', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'isbn'              => ['nullable', 'string', 'max:20'],
            'publisher'         => ['nullable', 'string', 'max:200'],
            'language'          => ['nullable', 'string', 'max:10'],
            'edition'           => ['nullable', 'string', 'max:50'],
            'page_count'        => ['nullable', 'integer', 'min:1'],
            'publication_year'  => ['nullable', 'integer', 'min:1000', 'max:2100'],
            'country_origin'    => ['nullable', 'string', 'max:3'],
            'price_cents'       => ['nullable', 'integer', 'min:0'],
            'is_free'           => ['sometimes', 'boolean'],
            'is_featured'       => ['sometimes', 'boolean'],
            'preview_pages'     => ['nullable', 'integer', 'min:0'],
        ]);

        $book->update($data);

        return response()->json(['data' => $book->fresh()]);
    }
}
