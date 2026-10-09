<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\Artwork;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Generates presentation derivatives from the confirmed primary image.
 *
 * Original is never modified — all derivatives are written to separate keys.
 *
 * Sizes:
 *   thumb  — 400 × 400  (square crop, for listing cards)
 *   medium — 800 px wide, proportional height
 *   large  — 1600 px wide, proportional height  (detail view)
 *
 * All derivatives are WebP for bandwidth efficiency.
 */
final class GenerateArtworkDerivatives
{
    private const SIZES = [
        'thumb'  => ['w' => 400,  'h' => 400,  'crop' => true],
        'medium' => ['w' => 800,  'h' => null, 'crop' => false],
        'large'  => ['w' => 1600, 'h' => null, 'crop' => false],
    ];

    public function execute(Artwork $artwork): array
    {
        if ($artwork->primary_image_key === null || $artwork->primary_image_status !== 'confirmed') {
            throw new \InvalidArgumentException(
                "Artwork {$artwork->id} has no confirmed primary image."
            );
        }

        $disk    = Storage::disk('s3');
        $source  = $disk->get($artwork->primary_image_key);
        $manager = new ImageManager(new Driver());
        $image   = $manager->decode($source);

        $derivativeKeys = [];

        foreach (self::SIZES as $name => ['w' => $w, 'h' => $h, 'crop' => $crop]) {
            $copy = clone $image;

            if ($crop && $h !== null) {
                $copy->cover($w, $h);
            } else {
                $copy->scale(width: $w);
            }

            $key     = $this->derivativeKey($artwork->primary_image_key, $name);
            $encoded = $copy->encode(new WebpEncoder(quality: 82));

            $disk->put($key, $encoded, ['ContentType' => 'image/webp', 'visibility' => 'public']);
            $derivativeKeys[$name] = $key;
        }

        // Persist derivative keys as JSON in a new column
        $artwork->update(['image_derivatives' => $derivativeKeys]);

        return $derivativeKeys;
    }

    private function derivativeKey(string $originalKey, string $size): string
    {
        // artworks/{id}/images/{uuid}.ext  →  artworks/{id}/images/derivatives/{size}.webp
        $dir = dirname($originalKey);
        return "{$dir}/derivatives/{$size}.webp";
    }
}
