<?php

declare(strict_types=1);

namespace App\Filament\Resources\DisputeResource\Pages;

use App\Filament\Resources\DisputeResource;
use Filament\Resources\Pages\ListRecords;

final class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;
}
