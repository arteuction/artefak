<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @tags External API
 */
final class ApiClientController extends Controller
{
    /**
     * List API clients (admin only).
     *
     * @response array{data: ApiClient[]}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $clients = ApiClient::with(['user:id,name,email', 'institution:id,name'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json(['data' => $clients]);
    }

    /**
     * Create a new API client and return the plaintext key once.
     *
     * The plaintext key is returned only in this response — it is not stored.
     *
     * @response 201 array{data: ApiClient, key: string}
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'scopes'                 => ['nullable', 'array'],
            'scopes.*'               => ['string', 'max:64'],
            'user_id'                => ['nullable', 'integer', 'exists:users,id'],
            'institution_id'         => ['nullable', 'integer', 'exists:institutions,id'],
            'rate_limit_per_minute'  => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        [$client, $plaintext] = ApiClient::generate(
            name:                 $data['name'],
            scopes:               $data['scopes'] ?? [],
            userId:               $data['user_id'] ?? null,
            institutionId:        $data['institution_id'] ?? null,
            rateLimitPerMinute:   $data['rate_limit_per_minute'] ?? null,
        );

        return response()->json([
            'data' => $client,
            'key'  => $plaintext,
        ], 201);
    }

    /**
     * Revoke an API client (admin only).
     *
     * @response 204
     */
    public function destroy(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorize('admin');

        $apiClient->update(['is_active' => false]);
        $apiClient->delete();

        return response()->json(null, 204);
    }
}
