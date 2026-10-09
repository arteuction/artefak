<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Book file management — track file versions for a book.
 *
 * Files are stored on a remote disk (S3 / local); this endpoint records
 * metadata only. Actual upload/download is handled separately (presigned URLs).
 * Files are append-only once status='published'; admins may mark obsolete.
 */
final class BookFileController extends Controller
{
    private function gate(Request $request, Book $book): void
    {
        $user = $request->user();
        $isOwner = $book->owner_id === $user->id;
        $isAdmin = in_array($user->role, ['admin', 'operator'], true);
        if (! $isOwner && ! $isAdmin) {
            abort(403);
        }
    }

    /**
     * GET /api/v1/books/{book}/files
     *
     * Owner or admin sees all; public has no access (files require entitlement).
     */
    public function index(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);
        return response()->json(['data' => $book->files()->orderByDesc('version')->get()]);
    }

    /**
     * POST /api/v1/books/{book}/files
     *
     * Register a new file version. Version auto-increments.
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $this->gate($request, $book);

        $data = $request->validate([
            'disk'       => ['required', 'string', 'max:50'],
            'path'       => ['required', 'string', 'max:1000'],
            'filename'   => ['required', 'string', 'max:255'],
            'mime'       => ['required', 'string', 'max:100'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'sha256'     => ['required', 'string', 'size:64'],
            'type'       => ['required', 'in:full,preview,sample,cover'],
        ]);

        $maxVersion = $book->files()->where('type', $data['type'])->max('version') ?? 0;

        $file = BookFile::create([
            ...$data,
            'book_id'     => $book->id,
            'uploaded_by' => $request->user()->id,
            'version'     => $maxVersion + 1,
            'status'      => 'processing',
        ]);

        return response()->json(['data' => $file], 201);
    }

    /**
     * PATCH /api/v1/books/{book}/files/{bookFile}
     *
     * Update file status (pending → published / obsolete).
     */
    public function update(Request $request, Book $book, BookFile $bookFile): JsonResponse
    {
        abort_if($bookFile->book_id !== $book->id, 404);
        $this->gate($request, $book);

        $data = $request->validate([
            'status' => ['required', 'in:processing,published,rejected'],
        ]);

        $bookFile->update($data);

        return response()->json(['data' => $bookFile->fresh()]);
    }

    /**
     * DELETE /api/v1/books/{book}/files/{bookFile}
     *
     * Hard-delete only allowed on pending files (published files become obsolete).
     */
    public function destroy(Request $request, Book $book, BookFile $bookFile): JsonResponse
    {
        abort_if($bookFile->book_id !== $book->id, 404);
        $this->gate($request, $book);

        if ($bookFile->status === 'published') {
            abort(422, 'Published files cannot be deleted; mark them rejected instead.');
        }

        $bookFile->delete();

        return response()->json(null, 204);
    }
}
