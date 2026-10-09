<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookFile;
use App\Services\BookFileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Secure S3 presigned upload/download for book files.
 *
 * Two-phase upload:
 *   1. POST /books/{book}/files/upload-intent — reserve a staging slot, get a
 *      presigned PUT URL.
 *   2. POST /book-files/{bookFile}/complete  — server verifies sha256, promotes
 *      staging object to published key.
 *
 * Entitlement-gated download:
 *   GET /my-books/{book}/download-url — verified purchaser gets a short-lived
 *   GET URL for the published full-text file.
 */
final class BookFileUploadController extends Controller
{
    public function __construct(private BookFileStorageService $storage) {}

    // ── Phase 1: upload intent ────────────────────────────────────────────────

    /**
     * POST /api/v1/books/{book}/files/upload-intent
     *
     * Creates a BookFile record in `processing` status and returns a presigned
     * PUT URL pointing to a unique staging path. The client PUTs the file
     * directly to S3 using this URL, then calls /complete.
     */
    public function uploadIntent(Request $request, Book $book): JsonResponse
    {
        $user = $request->user();
        $isOwner = $book->owner_id === $user->id;
        $isAdmin = in_array($user->role, ['admin', 'operator'], true);
        if (! $isOwner && ! $isAdmin) {
            abort(403);
        }

        $data = $request->validate([
            'filename'   => ['required', 'string', 'max:255'],
            'type'       => ['required', 'in:full,preview,audio_preview,cover'],
            'mime'       => ['required', 'string', 'max:100'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:524288000'], // 500 MB cap
            'sha256'     => ['required', 'string', 'size:64'],
        ]);

        $stagingKey = $this->storage->stagingKey($book->id, $data['filename']);
        $maxVersion = $book->files()->where('type', $data['type'])->max('version') ?? 0;

        $file = BookFile::create([
            'book_id'     => $book->id,
            'uploaded_by' => $user->id,
            'disk'        => config('filesystems.books_disk', 's3'),
            'path'        => $stagingKey,
            'filename'    => $data['filename'],
            'mime'        => $data['mime'],
            'size_bytes'  => $data['size_bytes'],
            'sha256'      => $data['sha256'],
            'type'        => $data['type'],
            'version'     => $maxVersion + 1,
            'status'      => 'processing',
        ]);

        $uploadUrl = $this->storage->uploadIntentUrl($stagingKey);

        return response()->json([
            'data'       => $file,
            'upload_url' => $uploadUrl,
        ], 201);
    }

    // ── Phase 2: complete upload ──────────────────────────────────────────────

    /**
     * POST /api/v1/book-files/{bookFile}/complete
     *
     * Server verifies the uploaded object: checks it exists in staging,
     * computes sha256, compares to the claimed hash. On match, promotes
     * to the stable published key and marks the file `published`. On
     * mismatch, marks it `rejected`.
     */
    public function complete(Request $request, BookFile $bookFile): JsonResponse
    {
        $book = Book::findOrFail($bookFile->book_id);
        $user = $request->user();
        $isOwner = $book->owner_id === $user->id;
        $isAdmin = in_array($user->role, ['admin', 'operator'], true);
        if (! $isOwner && ! $isAdmin) {
            abort(403);
        }

        if ($bookFile->status !== 'processing') {
            abort(422, 'Only files in processing status can be completed.');
        }

        if (! $this->storage->exists($bookFile->path)) {
            abort(422, 'Uploaded object not found in staging storage. Please retry the upload.');
        }

        $actualHash = $this->storage->sha256($bookFile->path);

        if ($actualHash !== $bookFile->sha256) {
            $bookFile->update(['status' => 'rejected']);
            return response()->json([
                'message' => 'SHA-256 mismatch. File marked rejected.',
                'data'    => $bookFile->fresh(),
            ], 422);
        }

        // Promote staging object to its stable published key.
        $publishedKey = $this->storage->publishedKey($book->id, $bookFile->id, $bookFile->filename);
        $this->storage->promote($bookFile->path, $publishedKey);
        $this->storage->deleteStaging($bookFile->path);

        $bookFile->update([
            'path'   => $publishedKey,
            'status' => 'published',
        ]);

        return response()->json(['data' => $bookFile->fresh()]);
    }

    // ── Entitlement-gated download ────────────────────────────────────────────

    /**
     * GET /api/v1/my-books/{book}/download-url
     *
     * Verifies the requesting user holds an active book_entitlement for the
     * book, then issues a short-lived presigned GET URL for the published
     * full-text file. Entitlement is checked on every call; revoking an
     * entitlement will prevent new URLs (already-issued URLs remain valid
     * until they expire, so keep TTL short: 5 minutes).
     */
    public function downloadUrl(Request $request, Book $book): JsonResponse
    {
        $user = $request->user();

        $hasEntitlement = DB::table('book_entitlements')
            ->where('book_id', $book->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists();

        if (! $hasEntitlement) {
            abort(403, 'You do not have an active entitlement for this book.');
        }

        $file = $book->files()
            ->where('type', 'full')
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();

        if ($file === null) {
            abort(404, 'No published full-text file available for this book yet.');
        }

        $url = $this->storage->downloadUrl($file->path, expirySeconds: 300);

        return response()->json([
            'url'        => $url,
            'expires_in' => 300,
            'filename'   => $file->filename,
        ]);
    }
}
