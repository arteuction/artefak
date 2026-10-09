<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\Artwork;
use Illuminate\Support\Facades\Storage;

/**
 * Confirms that a previously-requested image was successfully uploaded to S3.
 *
 * Called by the client after a direct S3 PUT completes.
 * Verifies the object exists at the expected key before activating it.
 * Transitions primary_image_status from 'pending' → 'confirmed'.
 */
final class ConfirmArtworkImageUpload
{
    public function execute(Artwork $artwork): Artwork
    {
        if ($artwork->primary_image_status !== 'pending') {
            throw new \DomainException(
                "Artwork #{$artwork->id} has no pending image upload (status: {$artwork->primary_image_status})."
            );
        }

        if (! $artwork->primary_image_key) {
            throw new \DomainException("Artwork #{$artwork->id} has no image key set.");
        }

        if (! Storage::disk('s3')->exists($artwork->primary_image_key)) {
            throw new \DomainException(
                "Image not found in storage at key '{$artwork->primary_image_key}'. Upload may not have completed."
            );
        }

        $artwork->update(['primary_image_status' => 'confirmed']);

        return $artwork->fresh();
    }
}
