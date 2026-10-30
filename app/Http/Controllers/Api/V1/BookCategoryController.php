<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Book category tree — public read, admin write.
 *
 * GET  /api/v1/book-categories           — full tree (roots + children)
 * GET  /api/v1/book-categories/{category} — single category with children + book count
 * POST /api/v1/admin/book-categories      — create (admin)
 * PATCH /api/v1/admin/book-categories/{category} — update (admin)
 * DELETE /api/v1/admin/book-categories/{category} — soft-or-deactivate (admin)
 */
final class BookCategoryController extends Controller
{
    /** GET /api/v1/book-categories */
    public function index(): JsonResponse
    {
        $roots = BookCategory::with(['children' => fn ($q) => $q->active()->orderBy('sort_order')])
            ->active()
            ->roots()
            ->orderBy('sort_order')
            ->withCount('books')
            ->get()
            ->map(function (BookCategory $cat): array {
                $arr = $cat->toArray();
                $arr['children'] = $cat->children->map(fn ($c) => array_merge(
                    $c->toArray(),
                    ['book_count' => $c->books()->published()->count()],
                ))->values();
                return $arr;
            });

        return response()->json(['data' => $roots]);
    }

    /** GET /api/v1/book-categories/{category} */
    public function show(BookCategory $category): JsonResponse
    {
        abort_if(! $category->is_active, 404);

        $category->load(['children' => fn ($q) => $q->active()->orderBy('sort_order'), 'parent:id,slug,name']);
        $category->loadCount('books');

        $data              = $category->toArray();
        $data['book_count'] = $category->books()->published()->count();

        return response()->json($data);
    }

    /** GET /api/v1/book-categories/{category}/books */
    public function books(Request $request, BookCategory $category): JsonResponse
    {
        abort_if(! $category->is_active, 404);

        $books = $category->books()
            ->published()
            ->with(['bookAuthors.author:id,name'])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($books);
    }

    // ── Admin mutations ───────────────────────────────────────────────────────

    /** POST /api/v1/admin/book-categories */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BookCategory::class);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'slug'        => ['nullable', 'string', 'max:120', 'unique:book_categories,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id'   => ['nullable', 'integer', 'exists:book_categories,id'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $data['slug'] ??= Str::slug($data['name']);
        $data['is_active'] = true;

        $category = BookCategory::create($data);

        return response()->json($category, 201);
    }

    /** PATCH /api/v1/admin/book-categories/{category} */
    public function update(Request $request, BookCategory $category): JsonResponse
    {
        $this->authorize('update', $category);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:120'],
            'slug'        => ['sometimes', 'string', 'max:120', 'unique:book_categories,slug,' . $category->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id'   => ['nullable', 'integer', 'exists:book_categories,id'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $category->update($data);

        return response()->json($category->fresh());
    }

    /** DELETE /api/v1/admin/book-categories/{category} — deactivates, does not hard-delete */
    public function destroy(BookCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->update(['is_active' => false]);

        return response()->json(['deactivated' => true]);
    }
}
