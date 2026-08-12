<?php

declare(strict_types=1);

namespace App\Domain\Artist;

use App\Models\AdminAuditLog;
use App\Models\ArtistApplication;
use App\Models\ArtistProfile;
use Illuminate\Support\Facades\DB;

final class SubmitApplication
{
    public function execute(
        ArtistProfile $profile,
        ?string $portfolioUrl    = null,
        ?string $motivation      = null,
        ?string $idDocumentPath  = null,
    ): ArtistApplication {
        return DB::transaction(function () use ($profile, $portfolioUrl, $motivation, $idDocumentPath): ArtistApplication {
            $version = $profile->applications()->max('version') + 1;

            $application = ArtistApplication::create([
                'artist_profile_id' => $profile->id,
                'status'            => 'submitted',
                'portfolio_url'     => $portfolioUrl,
                'motivation'        => $motivation,
                'id_document_path'  => $idDocumentPath,
                'version'           => $version,
            ]);

            if ($profile->status === 'pending') {
                $profile->update(['status' => 'under_review']);
            }

            AdminAuditLog::record($profile->user_id, $application, 'application.submitted', [
                'version' => $version,
            ]);

            return $application;
        });
    }
}
