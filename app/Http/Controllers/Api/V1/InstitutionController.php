<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * @tags Institutions
 */
final class InstitutionController extends Controller
{
    /**
     * List active partner institutions.
     *
     * @unauthenticated
     * @response array{data: Institution[]}
     */
    public function index(Request $request): JsonResponse
    {
        $institutions = Institution::where('is_active', true)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('country_code'), fn ($q) => $q->where('country_code', $request->country_code))
            ->orderBy('name')
            ->paginate(25);

        return response()->json(['data' => $institutions]);
    }

    /**
     * Get a single institution by slug.
     *
     * @unauthenticated
     * @response array{data: Institution}
     */
    public function show(Institution $institution): JsonResponse
    {
        return response()->json(['data' => $institution->load('artworks:id,title,slug')]);
    }

    /**
     * Create a new institution (admin only).
     *
     * @response 201 array{data: Institution}
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'type'                   => ['required', 'in:museum,gallery,university,foundation,other'],
            'country_code'           => ['nullable', 'string', 'size:2'],
            'website_url'            => ['nullable', 'url', 'max:512'],
            'api_endpoint'           => ['nullable', 'url', 'max:512'],
            'europeana_provider_id'  => ['nullable', 'string', 'max:255'],
            'contact_email'          => ['nullable', 'email', 'max:255'],
        ]);

        $data['slug'] = Str::slug($data['name']);

        $institution = Institution::create($data);

        return response()->json(['data' => $institution], 201);
    }

    /**
     * Update an institution (admin only).
     *
     * @response array{data: Institution}
     */
    public function update(Request $request, Institution $institution): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'name'                   => ['sometimes', 'string', 'max:255'],
            'type'                   => ['sometimes', 'in:museum,gallery,university,foundation,other'],
            'country_code'           => ['nullable', 'string', 'size:2'],
            'website_url'            => ['nullable', 'url', 'max:512'],
            'api_endpoint'           => ['nullable', 'url', 'max:512'],
            'europeana_provider_id'  => ['nullable', 'string', 'max:255'],
            'contact_email'          => ['nullable', 'email', 'max:255'],
            'is_active'              => ['sometimes', 'boolean'],
        ]);

        $institution->update($data);

        return response()->json(['data' => $institution->fresh()]);
    }

    /**
     * Link an artwork to this institution.
     *
     * @response 200 array{data: Institution}
     */
    public function attachArtwork(Request $request, Institution $institution): JsonResponse
    {
        $this->authorize('admin');

        $data = $request->validate([
            'artwork_id'   => ['required', 'integer', 'exists:artworks,id'],
            'relationship' => ['required', 'in:loan,deposit,permanent_transfer,exhibition'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ]);

        $institution->artworks()->attach($data['artwork_id'], [
            'relationship' => $data['relationship'],
            'start_date'   => $data['start_date'] ?? null,
            'end_date'     => $data['end_date'] ?? null,
            'notes'        => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $institution->fresh()->load('artworks:id,title,slug')]);
    }
}
