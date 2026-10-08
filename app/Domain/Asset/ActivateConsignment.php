<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Models\Consignment;
use InvalidArgumentException;

final class ActivateConsignment
{
    public function execute(Consignment $consignment): Consignment
    {
        if ($consignment->status !== 'draft') {
            throw new InvalidArgumentException(
                "Cannot activate consignment with status '{$consignment->status}'."
            );
        }

        $consignment->update([
            'status'     => 'active',
            'starts_at'  => $consignment->starts_at ?? now(),
        ]);

        return $consignment->fresh();
    }
}
