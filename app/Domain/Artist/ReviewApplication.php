<?php

declare(strict_types=1);

namespace App\Domain\Artist;

use App\Models\AdminAuditLog;
use App\Models\ArtistApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReviewApplication
{
    public function approve(ArtistApplication $application, User $reviewer, ?string $note = null): void
    {
        $this->transition($application, 'approved', $reviewer, $note);

        $profile = $application->artistProfile;
        $profile->update([
            'status'      => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        AdminAuditLog::record($reviewer->id, $profile, 'artist.approved', [
            'application_id' => $application->id,
            'note'           => $note,
        ]);
    }

    public function reject(ArtistApplication $application, User $reviewer, string $note): void
    {
        $this->transition($application, 'rejected', $reviewer, $note);

        $profile = $application->artistProfile;
        $profile->update([
            'status'      => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        AdminAuditLog::record($reviewer->id, $profile, 'artist.rejected', [
            'application_id' => $application->id,
            'note'           => $note,
        ]);
    }

    private function transition(ArtistApplication $application, string $status, User $reviewer, ?string $note): void
    {
        if (! in_array($application->status, ['submitted', 'under_review'], true)) {
            throw new InvalidArgumentException("Cannot review application in status [{$application->status}].");
        }

        DB::transaction(function () use ($application, $status, $reviewer, $note): void {
            $application->update([
                'status'      => $status,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });
    }
}
