<?php

declare(strict_types=1);

namespace App\Domain\Dispute;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use InvalidArgumentException;

final class AttachDisputeEvidence
{
    private const TYPES = ['document', 'photo', 'message_log', 'condition_report', 'other'];

    public function execute(
        Dispute $dispute,
        User    $submittedBy,
        string  $type,
        array   $attributes = [],
    ): DisputeEvidence {
        if ($dispute->isResolved()) {
            throw new \DomainException("Cannot attach evidence to a resolved dispute.");
        }

        if (! in_array($type, self::TYPES, true)) {
            $valid = implode(', ', self::TYPES);
            throw new InvalidArgumentException("Unknown evidence type '{$type}'. Valid: {$valid}.");
        }

        return DisputeEvidence::create([
            'dispute_id'    => $dispute->id,
            'submitted_by'  => $submittedBy->id,
            'type'          => $type,
            'document_path' => $attributes['document_path'] ?? null,
            'notes'         => $attributes['notes']         ?? null,
        ]);
    }
}
