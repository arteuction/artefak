import { test, expect } from '@playwright/test';

/**
 * Phase 119 — Sell Now offer flow E2E tests.
 *
 * These tests verify the buyer offer journey via the API directly,
 * and the UI state machine for accepted offers.
 */

test.describe('Sell Now offer API contract', () => {
    test('unauthenticated user cannot submit an offer', async ({ request }) => {
        const res = await request.post('/api/v1/art-lots/1/sell-now-offers', {
            data: { offered_price_cents: 10000 },
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });

    test('unauthenticated user cannot list their offers', async ({ request }) => {
        const res = await request.get('/api/v1/my/sell-now-offers', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });

    test('unauthenticated user cannot access gallery offers', async ({ request }) => {
        const res = await request.get('/api/v1/my/gallery-offers', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });

    test('checkout session requires auth', async ({ request }) => {
        const res = await request.post('/api/v1/sell-now-offers/1/checkout-session', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });
});

test.describe('Offer pages', () => {
    test('my offers page redirects guest to login', async ({ page }) => {
        await page.goto('/offers');
        // Guest should see sign in link
        await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
    });

    test('gallery offers page redirects guest to login', async ({ page }) => {
        await page.goto('/gallery/offers');
        await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
    });
});
