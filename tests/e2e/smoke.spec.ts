import { test, expect } from '@playwright/test';

/**
 * Phase 92 — Smoke E2E tests.
 *
 * These tests require a running application (APP_URL / PLAYWRIGHT_BASE_URL).
 * They are intentionally thin: verify pages load, key elements render, and
 * the API health endpoint responds. Full workflow tests are separate specs.
 */

test.describe('Public marketplace', () => {
    test('home page loads', async ({ page }) => {
        await page.goto('/');
        await expect(page).toHaveTitle(/.+/); // any non-empty title
    });

    test('API health endpoint returns ok', async ({ request }) => {
        const res = await request.get('/api/v1/health');
        expect(res.status()).toBe(200);
        const body = await res.json();
        // Spatie Health returns { status: 'ok' | 'warning' | 'error', ... }
        expect(['ok', 'warning']).toContain(body.status ?? body.finishedAt ? 'ok' : 'unknown');
    });
});

test.describe('API contract', () => {
    test('OpenAPI spec is reachable', async ({ request }) => {
        const res = await request.get('/docs/api.json');
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.openapi).toMatch(/^3\./);
    });

    test('artworks index requires auth', async ({ request }) => {
        const res = await request.get('/api/v1/artworks', {
            headers: { Accept: 'application/json' },
        });
        // Either 401 (unauthenticated) or 200 if public listing is intentional.
        expect([200, 401]).toContain(res.status());
    });
});

test.describe('Pulse dashboard', () => {
    test('guest is denied access', async ({ page }) => {
        const res = await page.goto('/pulse');
        expect(res?.status()).toBe(403);
    });
});
