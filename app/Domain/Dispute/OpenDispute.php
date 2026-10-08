<?php

declare(strict_types=1);

namespace App\Domain\Dispute;

use App\Domain\Outbox\AppendDomainEvent;
use App\Models\Dispute;
use App\Models\User;
use InvalidArgumentException;

/**
 * Opens a new dispute against a commercial subject.
 *
 * Business rules:
 *  - Type must be one of Dispute::TYPES
 *  - Exactly one subject FK must be set
 *  - A second open dispute of the same type on the same subject is rejected
 *    (prevents duplicate spam; resolved disputes do not block new ones)
 */
final class OpenDispute
{
    public function execute(
        User   $openedBy,
        string $type,
        string $description,
        array  $subject = [],
    ): Dispute {
        if (! in_array($type, Dispute::TYPES, true)) {
            $valid = implode(', ', Dispute::TYPES);
            throw new InvalidArgumentException("Unknown dispute type '{$type}'. Valid: {$valid}.");
        }

        $this->validateSubject($subject);
        $this->rejectDuplicate($type, $subject);

        $dispute = Dispute::create([
            'type'                 => $type,
            'description'          => $description,
            'opened_by'            => $openedBy->id,
            'art_lot_id'           => $subject['art_lot_id']           ?? null,
            'auction_item_id'      => $subject['auction_item_id']      ?? null,
            'sell_now_offer_id'    => $subject['sell_now_offer_id']    ?? null,
            'ownership_transfer_id'=> $subject['ownership_transfer_id'] ?? null,
        ]);

        (new AppendDomainEvent())->execute(
            aggregate:      $dispute,
            eventType:      'dispute.opened',
            payload:        [
                'type'       => $type,
                'opened_by'  => $openedBy->id,
                'subject'    => array_filter($subject),
            ],
            idempotencyKey: "dispute.opened:{$dispute->id}",
        );

        return $dispute;
    }

    private function validateSubject(array $subject): void
    {
        $set = array_filter([
            $subject['art_lot_id']            ?? null,
            $subject['auction_item_id']       ?? null,
            $subject['sell_now_offer_id']     ?? null,
            $subject['ownership_transfer_id'] ?? null,
        ]);

        if (count($set) === 0) {
            throw new InvalidArgumentException('A dispute must reference exactly one subject (art_lot_id, auction_item_id, sell_now_offer_id, or ownership_transfer_id).');
        }

        if (count($set) > 1) {
            throw new InvalidArgumentException('A dispute must reference exactly one subject, not multiple.');
        }
    }

    private function rejectDuplicate(string $type, array $subject): void
    {
        $query = Dispute::where('type', $type)->where('status', 'open');

        foreach (['art_lot_id', 'auction_item_id', 'sell_now_offer_id', 'ownership_transfer_id'] as $col) {
            if (! empty($subject[$col])) {
                $query->where($col, $subject[$col]);
            }
        }

        if ($query->exists()) {
            throw new \DomainException("An open dispute of type '{$type}' already exists for this subject.");
        }
    }
}
