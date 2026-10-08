<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Synthetic event dispatched by the domain event consumer after 'consignment.changes_requested'. */
final class ConsignmentChangesRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int    $consignmentId,
        public readonly string $reason,
        public readonly string $requestedByName,
    ) {}
}
