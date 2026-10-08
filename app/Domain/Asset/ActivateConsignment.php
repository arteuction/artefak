<?php

declare(strict_types=1);

namespace App\Domain\Asset;

use App\Domain\Outbox\AppendDomainEvent;
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

        $fresh = $consignment->fresh();

        (new AppendDomainEvent())->execute(
            aggregate: $fresh,
            eventType: 'consignment.activated',
            payload: [
                'artwork_id'   => $fresh->artwork_id,
                'owner_id'     => $fresh->owner_id,
                'consignor_id' => $fresh->consignor_id,
                'gallery_id'   => $fresh->gallery_id,
                'starts_at'    => $fresh->starts_at?->toIso8601String(),
            ],
            idempotencyKey: "consignment.activated:{$fresh->id}",
        );

        return $fresh;
    }
}
