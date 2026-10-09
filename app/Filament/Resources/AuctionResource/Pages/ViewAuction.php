<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuctionResource\Pages;

use App\Filament\Resources\AuctionResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewAuction extends ViewRecord
{
    protected static string $resource = AuctionResource::class;
}
