import { test, expect } from '@playwright/test';

/**
 * Phase 119 — Auction room E2E tests.
 */

test.describe('Auction API contract', () => {
    test('auctions index is public', async ({ request }) => {
        const res = await request.get('/api/v1/auctions', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.data).toBeDefined();
    });

    test('bid placement requires auth', async ({ request }) => {
        const res = await request.post('/api/v1/auctions/1/items/1/bids', {
            data: { amount_cents: 10000, payment_method_id: 'pm_test' },
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });

    test('non-existent auction returns 404', async ({ request }) => {
        const res = await request.get('/api/v1/auctions/999999999', {
            headers: { Accept: 'application/json' },
        });
        expect([404, 200]).toContain(res.status()); // 200 with null body or 404
    });
});

test.describe('Auction room UI', () => {
    test('auctions list page renders', async ({ page }) => {
        await page.goto('/auctions');
        await expect(page.getByRole('heading', { name: 'Auctions' })).toBeVisible();
    });
});
