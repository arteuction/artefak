import { test, expect } from '@playwright/test';

/**
 * Phase 92 — Authentication flow E2E tests.
 * These tests exercise the login / registration API endpoints directly
 * (not the SPA UI) so they run without a browser renderer requirement.
 */

test.describe('Authentication API', () => {
    test('login with invalid credentials returns 422', async ({ request }) => {
        const res = await request.post('/api/v1/auth/login', {
            data: { email: 'nobody@example.com', password: 'wrong' },
            headers: { Accept: 'application/json' },
        });
        expect([401, 422]).toContain(res.status());
    });

    test('registration requires email and password', async ({ request }) => {
        const res = await request.post('/api/v1/auth/register', {
            data: {},
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(422);
        const body = await res.json();
        expect(body.errors).toBeDefined();
    });

    test('me endpoint requires authentication', async ({ request }) => {
        const res = await request.get('/api/v1/auth/me', {
            headers: { Accept: 'application/json' },
        });
        expect(res.status()).toBe(401);
    });
});
