<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\ApiClient;
use App\Models\Institution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomically onboard a new partner institution.
 *
 * Creates an Institution row and a scoped API client in one transaction.
 * Returns the institution, the ApiClient, and the plaintext API key
 * (shown once — caller must pass it to the partner securely).
 *
 * @return array{institution: Institution, api_client: ApiClient, api_key: string}
 */
final class OnboardPartner
{
    public function execute(
        string  $name,
        string  $type,
        ?string $countryCode   = null,
        ?string $websiteUrl    = null,
        ?string $contactEmail  = null,
        array   $scopes        = ['artworks:read', 'lots:read', 'provenance:read'],
        ?int    $rateLimitPerMinute = 60,
    ): array {
        return DB::transaction(function () use (
            $name, $type, $countryCode, $websiteUrl, $contactEmail, $scopes, $rateLimitPerMinute
        ): array {
            $institution = Institution::create([
                'name'          => $name,
                'slug'          => Str::slug($name),
                'type'          => $type,
                'country_code'  => $countryCode,
                'website_url'   => $websiteUrl,
                'contact_email' => $contactEmail,
                'is_active'     => true,
            ]);

            [$client, $plaintext] = ApiClient::generate(
                name:                 "{$name} API",
                scopes:               $scopes,
                institutionId:        $institution->id,
                rateLimitPerMinute:   $rateLimitPerMinute,
            );

            return [
                'institution' => $institution,
                'api_client'  => $client,
                'api_key'     => $plaintext,
            ];
        });
    }
}
