<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Onboarding\OnboardPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Partner Onboarding
 */
final class PartnerOnboardingController extends Controller
{
    public function __construct(private OnboardPartner $onboarder) {}

    /**
     * Onboard a new partner institution.
     *
     * Creates the institution and a scoped API client in one atomic operation.
     * The plaintext API key is returned once and never stored — pass it to
     * the partner through a secure channel immediately.
     *
     * @response 201 array{institution: Institution, api_client: ApiClient, api_key: string}
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'type'                   => ['required', 'in:museum,gallery,university,foundation,other'],
            'country_code'           => ['nullable', 'string', 'size:2'],
            'website_url'            => ['nullable', 'url', 'max:512'],
            'contact_email'          => ['nullable', 'email', 'max:255'],
            'scopes'                 => ['nullable', 'array'],
            'scopes.*'               => ['string', 'max:64'],
            'rate_limit_per_minute'  => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $result = $this->onboarder->execute(
            name:               $data['name'],
            type:               $data['type'],
            countryCode:        $data['country_code'] ?? null,
            websiteUrl:         $data['website_url'] ?? null,
            contactEmail:       $data['contact_email'] ?? null,
            scopes:             $data['scopes'] ?? ['artworks:read', 'lots:read', 'provenance:read'],
            rateLimitPerMinute: $data['rate_limit_per_minute'] ?? 60,
        );

        return response()->json($result, 201);
    }
}
