<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArtworkResource\Pages;

use App\Filament\Resources\ArtworkResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewArtwork extends ViewRecord
{
    protected static string $resource = ArtworkResource::class;
}
