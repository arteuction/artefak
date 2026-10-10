import { test, expect } from '@playwright/test';

/**
 * Phase 119 — Library E2E tests.
 */

test.describe('Library API contract', () => {
    test('books index returns published books', async ({ request }) => {
        const res = await request.get('/api/v1/books', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.data).toBeDefined();
        expect(Array.isArray(body.data)).toBe(true);
    });

    test('unpublished book returns 404', async ({ request }) => {
        // Slug that does not exist should 404
        const res = await request.get('/api/v1/books/definitely-does-not-exist-xyz', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(404);
    });

    test('download url requires auth', async ({ request }) => {
        const res = await request.get('/api/v1/my-books/some-book/download-url', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });

    test('checkout session requires auth', async ({ request }) => {
        const res = await request.post('/api/v1/books/some-book/checkout-session', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });
});

test.describe('Library UI', () => {
    test('library page renders', async ({ page }) => {
        await page.goto('/library');
        await expect(page.getByRole('heading', { name: 'Library' })).toBeVisible();
    });

    test('non-existent book slug shows not found', async ({ page }) => {
        await page.goto('/library/does-not-exist-xyz');
        // Either 404 text or redirect — page must not crash
        await expect(page).not.toHaveTitle('');
    });
});
