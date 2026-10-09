<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConsignmentResource\Pages;

use App\Filament\Resources\ConsignmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListConsignments extends ListRecords
{
    protected static string $resource = ConsignmentResource::class;
}
