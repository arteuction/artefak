<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Governance\EraseUserData;
use App\Domain\Governance\RecordConsent;
use App\Models\GovernancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags Governance
 */
final class GovernanceController extends Controller
{
    public function __construct(
        private RecordConsent $recorder,
        private EraseUserData $eraser,
    ) {}

    /**
     * List active governance policies.
     *
     * @unauthenticated
     * @response array{data: GovernancePolicy[]}
     */
    public function policies(): JsonResponse
    {
        $policies = GovernancePolicy::where('is_active', true)
            ->orderBy('type')
            ->get(['id', 'type', 'version', 'effective_from', 'content_url']);

        return response()->json(['data' => $policies]);
    }

    /**
     * Record consent to a governance policy.
     *
     * @response 200 array{data: UserConsent}
     */
    public function consent(Request $request, GovernancePolicy $governancePolicy): JsonResponse
    {
        $user    = $request->user();
        $consent = $this->recorder->execute($user, $governancePolicy, $request);

        return response()->json(['data' => $consent]);
    }

    /**
     * Withdraw consent from a governance policy (GDPR Art. 7(3)).
     *
     * @response 204
     */
    public function withdrawConsent(Request $request, GovernancePolicy $governancePolicy): JsonResponse
    {
        $this->recorder->withdraw($request->user(), $governancePolicy);
        return response()->json(null, 204);
    }

    /**
     * List the authenticated user's consents.
     *
     * @response array{data: UserConsent[]}
     */
    public function myConsents(Request $request): JsonResponse
    {
        $consents = $request->user()
            ->consents()
            ->with('policy:id,type,version,effective_from')
            ->orderByDesc('consented_at')
            ->get();

        return response()->json(['data' => $consents]);
    }

    /**
     * Erasure request — GDPR Article 17.
     *
     * Anonymises the authenticated user's personal data while preserving
     * legally required financial records. The account is not deleted.
     *
     * @response 200 array{message: string}
     */
    public function requestErasure(Request $request): JsonResponse
    {
        $this->eraser->execute($request->user());

        return response()->json(['message' => 'Personal data has been erased.']);
    }
}
