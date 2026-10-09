<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\Artwork;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Issues a presigned S3 PUT URL for direct-to-S3 image upload.
 *
 * Flow:
 *   1. Generate a unique S3 object key under artworks/{id}/images/{uuid}.{ext}
 *   2. Store key + 'pending' status on the artwork (idempotent: same key if already pending)
 *   3. Return the presigned URL and the key for the client to PUT the file to
 *
 * The client uploads directly to S3; the server is never in the upload path.
 * After upload, the client calls ConfirmArtworkImageUpload to verify and activate.
 */
final class RequestArtworkImageUpload
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const URL_TTL_MINUTES    = 15;
    private const MAX_BYTES          = 10 * 1024 * 1024; // 10 MB

    public function execute(Artwork $artwork, string $extension = 'jpg'): array
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported image extension '{$extension}'. Allowed: " . implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }

        $key = "artworks/{$artwork->id}/images/" . Str::uuid() . ".{$extension}";

        $artwork->update([
            'primary_image_key'    => $key,
            'primary_image_status' => 'pending',
        ]);

        $uploadUrl = Storage::disk('s3')->temporaryUploadUrl(
            $key,
            now()->addMinutes(self::URL_TTL_MINUTES),
            [
                'ContentType' => $this->mimeType($extension),
                'ContentLengthRange' => [0, self::MAX_BYTES],
            ],
        );

        return [
            'upload_url'         => $uploadUrl,
            'key'                => $key,
            'expires_in_seconds' => self::URL_TTL_MINUTES * 60,
            'max_bytes'          => self::MAX_BYTES,
        ];
    }

    private function mimeType(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };
    }
}
