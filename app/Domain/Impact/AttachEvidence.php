<?php

declare(strict_types=1);

namespace App\Domain\Impact;

use App\Models\Evidence;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Attaches a typed evidence record to any subject model.
 *
 * The evidence type must be one of the 8 canonical types defined in Evidence::TYPES.
 * Verification lifecycle is left to a separate action (admin/operator concern).
 */
final class AttachEvidence
{
    public function execute(
        Model   $subject,
        string  $type,
        array   $attributes = [],
    ): Evidence {
        if (! in_array($type, Evidence::TYPES, true)) {
            $valid = implode(', ', Evidence::TYPES);
            throw new InvalidArgumentException("Unknown evidence type '{$type}'. Valid: {$valid}.");
        }

        return Evidence::create([
            'subject_type'        => $subject->getMorphClass(),
            'subject_id'          => $subject->getKey(),
            'type'                => $type,
            'subtype'             => $attributes['subtype']          ?? null,
            'document_path'       => $attributes['document_path']    ?? null,
            'document_mime'       => $attributes['document_mime']    ?? null,
            'issuer'              => $attributes['issuer']           ?? null,
            'issued_at'           => $attributes['issued_at']        ?? null,
            'verification_status' => $attributes['verification_status'] ?? 'pending',
            'impact_project_id'   => $attributes['impact_project_id'] ?? null,
            'notes'               => $attributes['notes']            ?? null,
        ]);
    }
}
