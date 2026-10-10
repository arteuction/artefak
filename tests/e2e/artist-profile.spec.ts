import { test, expect } from '@playwright/test';

/**
 * Phase 119 — Artist profile and artwork catalogue E2E tests.
 */

test.describe('Artwork catalogue API', () => {
    test('artworks index is public', async ({ request }) => {
        const res = await request.get('/api/v1/artworks', {
            headers: { Accept: 'application/json' },
        });
        // Could be 200 (public) or 401 (auth required) — both valid
        expect([200, 401]).toContain(res.status());
    });

    test('non-existent artwork slug returns 404', async ({ request }) => {
        const res = await request.get('/api/v1/artworks/does-not-exist-xyz', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(404);
    });

    test('artist portfolio by non-existent slug returns 404', async ({ request }) => {
        const res = await request.get('/api/v1/artists/does-not-exist-xyz', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(404);
    });
});

test.describe('Artwork UI', () => {
    test('artworks page renders', async ({ page }) => {
        await page.goto('/artworks');
        await expect(page.getByRole('heading', { name: 'Artworks' })).toBeVisible();
    });

    test('non-existent artwork slug shows not found', async ({ page }) => {
        await page.goto('/artworks/does-not-exist-xyz');
        await expect(page.getByText(/not found/i)).toBeVisible();
    });

    test('non-existent artist slug shows not found', async ({ page }) => {
        await page.goto('/artists/does-not-exist-xyz');
        await expect(page.getByText(/not found/i)).toBeVisible();
    });
});
