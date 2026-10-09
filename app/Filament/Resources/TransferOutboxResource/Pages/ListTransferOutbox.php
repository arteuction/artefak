<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransferOutboxResource\Pages;

use App\Filament\Resources\TransferOutboxResource;
use Filament\Resources\Pages\ListRecords;

final class ListTransferOutbox extends ListRecords
{
    protected static string $resource = TransferOutboxResource::class;
}
