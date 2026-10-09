<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuctionResource\Pages;

use App\Filament\Resources\AuctionResource;
use Filament\Resources\Pages\ListRecords;

final class ListAuctions extends ListRecords
{
    protected static string $resource = AuctionResource::class;
}
