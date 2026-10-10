<?php

declare(strict_types=1);

namespace App\Domain\Rights;

use Spatie\LaravelData\Data;

/**
 * Rights declaration for an artwork, aligned with ODRL / Creative Commons.
 *
 * license_spdx     — SPDX or CC license string ("CC BY-NC 4.0", "All Rights Reserved")
 * resale_royalty_bps — Droit de suite in basis points (0–5000). Null = undeclared.
 * rights_statement — ODRL Policy URI or free-text statement.
 */
final class RightsData extends Data
{
    public function __construct(
        public readonly ?string $license_spdx,
        public readonly ?int    $resale_royalty_bps,
        public readonly ?string $rights_statement,
    ) {}

    public static function fromArtwork(\App\Models\Artwork $artwork): self
    {
        return new self(
            license_spdx:        $artwork->license_spdx,
            resale_royalty_bps:  $artwork->resale_royalty_bps,
            rights_statement:    $artwork->rights_statement,
        );
    }

    /** Resale royalty as a human-readable percentage string, or null. */
    public function resaleRoyaltyPercent(): ?string
    {
        if ($this->resale_royalty_bps === null) {
            return null;
        }
        return number_format($this->resale_royalty_bps / 100, 2) . '%';
    }
}
