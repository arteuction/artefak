<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Artwork;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

final class ArtworkData extends Data
{
    public function __construct(
        public readonly int              $id,
        public readonly string           $title,
        public readonly string           $slug,
        public readonly string           $status,
        public readonly ?string          $medium,
        public readonly ?string          $dimensions,
        public readonly ?int             $year_created,
        public readonly ?string          $description,
        public readonly bool             $is_original,
        public readonly ?int             $edition_number,
        public readonly ?int             $edition_total,
        public readonly ?Carbon          $created_at,
        public readonly ?Carbon          $updated_at,
        public readonly ?ArtistSummaryData $artist,
    ) {}

    public static function fromArtwork(Artwork $artwork): self
    {
        $artist = $artwork->relationLoaded('artist') && $artwork->artist
            ? ArtistSummaryData::fromUser($artwork->artist)
            : null;

        return new self(
            id:             $artwork->id,
            title:          $artwork->title,
            slug:           $artwork->slug,
            status:         $artwork->status,
            medium:         $artwork->medium,
            dimensions:     $artwork->dimensions,
            year_created:   $artwork->year_created,
            description:    $artwork->description,
            is_original:    (bool) ($artwork->is_original ?? true),
            edition_number: $artwork->edition_number,
            edition_total:  $artwork->edition_total,
            created_at:     $artwork->created_at,
            updated_at:     $artwork->updated_at,
            artist:         $artist,
        );
    }
}
