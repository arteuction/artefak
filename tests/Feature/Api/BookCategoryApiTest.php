<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoot(string $slug = 'fine-art', int $order = 1): BookCategory
    {
        return BookCategory::create([
            'slug'       => $slug,
            'name'       => ucfirst(str_replace('-', ' ', $slug)),
            'sort_order' => $order,
            'is_active'  => true,
        ]);
    }

    private function makeChild(BookCategory $parent, string $slug): BookCategory
    {
        return BookCategory::create([
            'slug'      => $slug,
            'name'      => ucfirst(str_replace('-', ' ', $slug)),
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);
    }

    private function makePublishedBook(User $owner, BookCategory $category): Book
    {
        $book = Book::create([
            'owner_id'    => $owner->id,
            'title'       => 'Book ' . uniqid(),
            'slug'        => 'book-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'published',
        ]);
        $book->categories()->attach($category->id);
        return $book;
    }

    // ── GET /api/v1/book-categories ──────────────────────────────────────────

    public function test_index_returns_active_root_categories_with_children(): void
    {
        $root  = $this->makeRoot('fine-art');
        $child = $this->makeChild($root, 'painting');
        $this->makeRoot('inactive-cat')->update(['is_active' => false]);

        $this->getJson('/api/v1/book-categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'fine-art')
            ->assertJsonCount(1, 'data')               // inactive excluded
            ->assertJsonPath('data.0.children.0.slug', 'painting');
    }

    public function test_index_is_public(): void
    {
        $this->makeRoot();
        $this->getJson('/api/v1/book-categories')->assertOk();
    }

    // ── GET /api/v1/book-categories/{category} ───────────────────────────────

    public function test_show_returns_category_with_children(): void
    {
        $root  = $this->makeRoot('photography');
        $this->makeChild($root, 'documentary');

        $this->getJson("/api/v1/book-categories/{$root->id}")
            ->assertOk()
            ->assertJsonPath('slug', 'photography')
            ->assertJsonCount(1, 'children');
    }

    public function test_show_404_for_inactive(): void
    {
        $cat = $this->makeRoot();
        $cat->update(['is_active' => false]);

        $this->getJson("/api/v1/book-categories/{$cat->id}")->assertNotFound();
    }

    // ── GET /api/v1/book-categories/{category}/books ─────────────────────────

    public function test_books_returns_published_books_in_category(): void
    {
        $artist = User::factory()->create(['role' => 'artist']);
        $cat    = $this->makeRoot('art-history');
        $this->makePublishedBook($artist, $cat);

        // unpublished book — should not appear
        $draft = Book::create([
            'owner_id'    => $artist->id,
            'title'       => 'Draft',
            'slug'        => 'draft-' . uniqid(),
            'price_cents' => 0,
            'currency'    => 'EUR',
            'is_free'     => true,
            'status'      => 'draft',
        ]);
        $draft->categories()->attach($cat->id);

        $this->getJson("/api/v1/book-categories/{$cat->id}/books")
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_books_404_for_inactive_category(): void
    {
        $cat = $this->makeRoot();
        $cat->update(['is_active' => false]);

        $this->getJson("/api/v1/book-categories/{$cat->id}/books")->assertNotFound();
    }

    // ── POST /api/v1/admin/book-categories ───────────────────────────────────

    public function test_admin_can_create_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/book-categories', [
                'name' => 'Sculpture',
                'slug' => 'sculpture',
            ])
            ->assertCreated()
            ->assertJsonPath('slug', 'sculpture');
    }

    public function test_create_generates_slug_from_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/book-categories', ['name' => 'Folk Art'])
            ->assertCreated();

        $this->assertSame('folk-art', $res->json('slug'));
    }

    public function test_create_requires_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/book-categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_guest_cannot_create_category(): void
    {
        $this->postJson('/api/v1/admin/book-categories', ['name' => 'X'])->assertUnauthorized();
    }

    // ── PATCH /api/v1/admin/book-categories/{category} ───────────────────────

    public function test_admin_can_update_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cat   = $this->makeRoot('old-name');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/book-categories/{$cat->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('name', 'New Name');
    }

    // ── DELETE /api/v1/admin/book-categories/{category} ──────────────────────

    public function test_admin_can_deactivate_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cat   = $this->makeRoot();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/book-categories/{$cat->id}")
            ->assertOk()
            ->assertJsonPath('deactivated', true);

        $this->assertFalse($cat->fresh()->is_active);
    }
}
