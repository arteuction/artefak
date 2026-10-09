<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReconciliationMismatchResource\Pages;

use App\Filament\Resources\ReconciliationMismatchResource;
use Filament\Resources\Pages\ListRecords;

final class ListReconciliationMismatches extends ListRecords
{
    protected static string $resource = ReconciliationMismatchResource::class;
}
