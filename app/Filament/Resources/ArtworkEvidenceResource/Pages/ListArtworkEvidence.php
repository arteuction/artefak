<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArtworkEvidenceResource\Pages;

use App\Filament\Resources\ArtworkEvidenceResource;
use Filament\Resources\Pages\ListRecords;

final class ListArtworkEvidence extends ListRecords
{
    protected static string $resource = ArtworkEvidenceResource::class;
}
