<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArtworkResource\Pages;

use App\Filament\Resources\ArtworkResource;
use Filament\Resources\Pages\ListRecords;

final class ListArtworks extends ListRecords
{
    protected static string $resource = ArtworkResource::class;
}
