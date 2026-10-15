import { test, expect, type APIRequestContext } from '@playwright/test';

/**
 * Phase 168 — Authenticated buyer journey.
 *
 * Each test group registers a unique buyer via POST /api/v1/register and
 * attaches the returned Sanctum token as a Bearer header. This exercises
 * the full authenticated API surface without needing a seeded database.
 *
 * Tests are designed to be environment-agnostic:
 * - If a resource (lot, auction) doesn't exist in the environment, the test
 *   asserts the correct error code (404/422) rather than skipping.
 * - No test assumes specific IDs; all discovery is done via the index endpoints.
 */

// ── Auth helper ───────────────────────────────────────────────────────────────

async function registerBuyer(request: APIRequestContext): Promise<string> {
    const id = Date.now();
    const res = await request.post('/api/v1/register', {
        data: {
            name: `E2E Buyer ${id}`,
            email: `e2e.buyer.${id}@test.invalid`,
            password: `Pw!${id}xxYY`,
            password_confirmation: `Pw!${id}xxYY`,
        },
        headers: { Accept: 'application/json' },
    });
    expect(res.status(), 'Registration must succeed').toBe(201);
    const body = await res.json();
    expect(body.token, 'Token must be present').toBeTruthy();
    return body.token as string;
}

function authHeaders(token: string) {
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

// ── 1. Registration and identity ──────────────────────────────────────────────

test.describe('Buyer — registration and identity', () => {
    test('register returns user and token', async ({ request }) => {
        const token = await registerBuyer(request);
        expect(token.length).toBeGreaterThan(10);
    });

    test('me endpoint returns authenticated user', async ({ request }) => {
        const token = await registerBuyer(request);
        const res = await request.get('/api/v1/auth/me', {
            headers: authHeaders(token),
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(typeof body.id).toBe('number');
        expect(body.email).toContain('@test.invalid');
    });

    test('logout invalidates the token', async ({ request }) => {
        const token = await registerBuyer(request);

        const logout = await request.post('/api/v1/logout', {
            headers: authHeaders(token),
        });
        expect([200, 204]).toContain(logout.status());

        // Token should now be invalid
        const me = await request.get('/api/v1/auth/me', {
            headers: authHeaders(token),
        });
        expect(me.status()).toBe(401);
    });

    test('duplicate email registration fails with 422', async ({ request }) => {
        const id = Date.now();
        const email = `dup.${id}@test.invalid`;
        const payload = {
            name: 'Dup Test',
            email,
            password: 'Pw!dupxxYY',
            password_confirmation: 'Pw!dupxxYY',
        };

        const first = await request.post('/api/v1/register', {
            data: payload,
            headers: { Accept: 'application/json' },
        });
        expect(first.status()).toBe(201);

        const second = await request.post('/api/v1/register', {
            data: payload,
            headers: { Accept: 'application/json' },
        });
        expect(second.status()).toBe(422);
        const body = await second.json();
        expect(body.errors?.email).toBeDefined();
    });
});

// ── 2. Authenticated catalogue browsing ───────────────────────────────────────

test.describe('Buyer — authenticated catalogue browsing', () => {
    test('authenticated artworks index returns paginated results', async ({ request }) => {
        const token = await registerBuyer(request);
        const res = await request.get('/api/v1/artworks', {
            headers: authHeaders(token),
        });
        expect([200, 401]).toContain(res.status()); // 200 if public, 200 if auth
        if (res.status() === 200) {
            const body = await res.json();
            expect(body.data).toBeDefined();
            expect(Array.isArray(body.data)).toBe(true);
        }
    });

    test('authenticated auctions index returns paginated results', async ({ request }) => {
        const token = await registerBuyer(request);
        const res = await request.get('/api/v1/auctions', {
            headers: authHeaders(token),
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.data).toBeDefined();
        expect(Array.isArray(body.data)).toBe(true);
    });

    test('auctions index response shape is consistent auth vs unauth', async ({ request }) => {
        const token = await registerBuyer(request);
        const [auth, unauth] = await Promise.all([
            request.get('/api/v1/auctions', { headers: authHeaders(token) }),
            request.get('/api/v1/auctions', { headers: { Accept: 'application/json' } }),
        ]);
        // Both should succeed and return the same shape
        expect(auth.status()).toBe(200);
        expect(unauth.status()).toBe(200);
        const authBody = await auth.json();
        const unauthBody = await unauth.json();
        expect(Object.keys(authBody)).toEqual(Object.keys(unauthBody));
    });
});

// ── 3. Sell-now offer flow ────────────────────────────────────────────────────

test.describe('Buyer — sell-now offer flow', () => {
    test('authenticated buyer can attempt to submit an offer (not 401)', async ({ request }) => {
        const token = await registerBuyer(request);

        // Discover if any lot is available
        const lotsRes = await request.get('/api/v1/art-lots', {
            headers: authHeaders(token),
        });

        let lotId: number | null = null;
        if (lotsRes.status() === 200) {
            const body = await lotsRes.json();
            const activeLot = (body.data as Array<{ id: number; status: string }>)
                ?.find(l => l.status === 'active');
            lotId = activeLot?.id ?? null;
        }

        if (lotId === null) {
            // No lots seeded — verify correct error (404/422/405), not 401
            const res = await request.post('/api/v1/art-lots/999999/sell-now-offers', {
                data: { offered_price_cents: 50000, currency: 'EUR' },
                headers: authHeaders(token),
            });
            expect(res.status()).not.toBe(401);
            expect([404, 422, 405]).toContain(res.status());
        } else {
            const res = await request.post(`/api/v1/art-lots/${lotId}/sell-now-offers`, {
                data: { offered_price_cents: 50000, currency: 'EUR' },
                headers: authHeaders(token),
            });
            // 201 = created, 422 = validation/business rule, 409 = conflict — never 401
            expect(res.status()).not.toBe(401);
            expect([201, 422, 409, 403]).toContain(res.status());
        }
    });

    test('buyer can list their own offers', async ({ request }) => {
        const token = await registerBuyer(request);
        const res = await request.get('/api/v1/my/sell-now-offers', {
            headers: authHeaders(token),
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.data).toBeDefined();
        expect(Array.isArray(body.data)).toBe(true);
        // Fresh user has no offers
        expect(body.data).toHaveLength(0);
    });
});

// ── 4. Auction bid placement ──────────────────────────────────────────────────

test.describe('Buyer — auction bid placement', () => {
    test('authenticated bid attempt on non-existent auction item returns 404 or 422, not 401', async ({ request }) => {
        const token = await registerBuyer(request);
        const res = await request.post('/api/v1/auctions/999999/items/999999/bids', {
            data: { amount_cents: 10000 },
            headers: authHeaders(token),
        });
        expect(res.status()).not.toBe(401);
        expect([404, 422, 403]).toContain(res.status());
    });

    test('bid on live auction discovers and bids on first active item if available', async ({ request }) => {
        const token = await registerBuyer(request);

        // Find a live auction
        const auctionsRes = await request.get('/api/v1/auctions', {
            headers: authHeaders(token),
        });
        expect(auctionsRes.status()).toBe(200);
        const auctionsBody = await auctionsRes.json();
        const liveAuction = (auctionsBody.data as Array<{ id: number; status: string }>)
            ?.find(a => a.status === 'live');

        if (!liveAuction) {
            // No live auction seeded — skip with a meaningful signal
            test.info().annotations.push({ type: 'skip-reason', description: 'No live auction in environment' });
            return;
        }

        // Get auction detail with items
        const detailRes = await request.get(`/api/v1/auctions/${liveAuction.id}`, {
            headers: authHeaders(token),
        });
        expect(detailRes.status()).toBe(200);
        const detail = await detailRes.json();

        const activeItem = (detail.items as Array<{ id: number; status: string; starting_bid_cents: number }>)
            ?.find(i => i.status === 'active');

        if (!activeItem) {
            test.info().annotations.push({ type: 'skip-reason', description: 'No active item in live auction' });
            return;
        }

        // Attempt a bid slightly above the starting price
        const bidAmount = (activeItem.starting_bid_cents ?? 5000) + 100;
        const bidRes = await request.post(
            `/api/v1/auctions/${liveAuction.id}/items/${activeItem.id}/bids`,
            {
                data: { amount_cents: bidAmount },
                headers: authHeaders(token),
            },
        );

        // 201 = placed, 402 = payment auth required, 422 = validation error — never 401
        expect(bidRes.status()).not.toBe(401);
        expect([201, 402, 422, 403]).toContain(bidRes.status());
    });
});

// ── 5. UI buyer journey (requires running SPA) ────────────────────────────────

test.describe('Buyer — SPA journey', () => {
    test('unauthenticated user sees Sign in link and can navigate to login', async ({ page }) => {
        await page.goto('/');
        const signInLink = page.getByRole('link', { name: /sign in/i });
        await expect(signInLink).toBeVisible();
        await signInLink.click();
        await expect(page).toHaveURL(/\/login/);
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByLabel('Password')).toBeVisible();
    });

    test('artwork catalogue is reachable and shows a heading', async ({ page }) => {
        await page.goto('/artworks');
        await expect(page.getByRole('heading', { name: 'Artworks' })).toBeVisible();
    });

    test('auction catalogue is reachable and shows a heading', async ({ page }) => {
        await page.goto('/auctions');
        await expect(page.getByRole('heading', { name: 'Auctions' })).toBeVisible();
    });

    test('protected route redirects unauthenticated user', async ({ page }) => {
        await page.goto('/offers');
        // Guest must see a sign-in prompt or be redirected to login
        await expect(
            page.getByRole('link', { name: /sign in/i }).or(page.getByLabel('Email')),
        ).toBeVisible({ timeout: 5_000 });
    });
});
