<?php

declare(strict_types=1);

namespace App\Domain\Artist;

use App\Models\AdminAuditLog;
use App\Models\ArtworkSdgClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewSdgClaim
{
    public function approve(ArtworkSdgClaim $claim, User $reviewer, ?string $note = null): void
    {
        $this->transition($claim, 'approved', $reviewer, $note);

        AdminAuditLog::record($reviewer->id, $claim, 'sdg_claim.approved', [
            'sdg_number' => $claim->sdg_number,
            'artwork_id' => $claim->artwork_id,
        ]);
    }

    public function reject(ArtworkSdgClaim $claim, User $reviewer, string $note): void
    {
        $this->transition($claim, 'rejected', $reviewer, $note);

        AdminAuditLog::record($reviewer->id, $claim, 'sdg_claim.rejected', [
            'sdg_number' => $claim->sdg_number,
            'artwork_id' => $claim->artwork_id,
            'note'       => $note,
        ]);
    }

    private function transition(ArtworkSdgClaim $claim, string $status, User $reviewer, ?string $note): void
    {
        if ($claim->status !== 'pending') {
            throw new InvalidArgumentException("Can only review pending claims; claim is [{$claim->status}].");
        }

        DB::transaction(function () use ($claim, $status, $reviewer, $note): void {
            $claim->update([
                'status'      => $status,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });
    }
}
