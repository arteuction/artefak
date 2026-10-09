<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Abstracts S3 presigned URL generation for book files.
 *
 * Two-phase upload design:
 *   1. Client receives a presigned PUT URL pointing to a unique staging key.
 *   2. After upload, client calls /complete; server reads the staging object,
 *      verifies sha256, and promotes to the published key.
 *
 * This prevents clients from choosing arbitrary storage paths and ensures
 * every published file was server-verified before becoming downloadable.
 */
class BookFileStorageService
{
    private string $disk;

    public function __construct(?string $disk = null)
    {
        $this->disk = $disk ?? config('filesystems.books_disk', 's3');
    }

    /** Generate a unique staging key for an upload. */
    public function stagingKey(int $bookId, string $filename): string
    {
        return sprintf('books/staging/%d/%s/%s', $bookId, Str::uuid(), $filename);
    }

    /** Promote a staging key to a stable published key. */
    public function publishedKey(int $bookId, int $fileId, string $filename): string
    {
        return sprintf('books/published/%d/%d/%s', $bookId, $fileId, $filename);
    }

    /**
     * Presigned PUT URL for a client to upload directly to staging.
     *
     * On real S3 this returns a signed URL string; on the fake test disk
     * temporaryUploadUrl() returns [url, headers] — we normalise to a string.
     * On local disks that don't support presigned URLs a fallback is returned.
     */
    public function uploadIntentUrl(string $stagingKey, int $expirySeconds = 300): string
    {
        try {
            $result = Storage::disk($this->disk)
                ->temporaryUploadUrl($stagingKey, now()->addSeconds($expirySeconds));

            // Real S3: string; fake/minio disk: ['url' => '...', 'headers' => [...]]
            return is_array($result) ? (string) ($result['url'] ?? array_values($result)[0]) : (string) $result;
        } catch (\RuntimeException) {
            return url('/fake-upload/' . urlencode($stagingKey));
        }
    }

    /**
     * Presigned GET URL for a buyer to download a published file.
     * Returns a fallback URL on non-S3 disks (local/test).
     */
    public function downloadUrl(string $publishedKey, int $expirySeconds = 300): string
    {
        try {
            return (string) Storage::disk($this->disk)
                ->temporaryUrl($publishedKey, now()->addSeconds($expirySeconds));
        } catch (\RuntimeException) {
            return url('/fake-download/' . urlencode($publishedKey));
        }
    }

    /** Check that an object exists at a given storage path. */
    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    /** Read an object and compute its sha256 hash. */
    public function sha256(string $path): ?string
    {
        $contents = Storage::disk($this->disk)->get($path);
        if ($contents === null) {
            return null;
        }
        return hash('sha256', $contents);
    }

    /** Copy a file from staging key to published key on the same disk. */
    public function promote(string $fromKey, string $toKey): bool
    {
        return Storage::disk($this->disk)->copy($fromKey, $toKey);
    }

    /** Delete a staging object (cleanup after promotion or rejection). */
    public function deleteStaging(string $stagingKey): void
    {
        Storage::disk($this->disk)->delete($stagingKey);
    }
}
