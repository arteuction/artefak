import { test, expect } from '@playwright/test';

/**
 * Playwright end-to-end smoke tests for the ARTeuCtion marketplace SPA.
 * These run against a live dev server (npm run dev + php artisan serve).
 */

test.describe('Marketplace SPA', () => {
    test('homepage loads and shows navigation', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByRole('link', { name: 'ARTeuCtion' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Artworks' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Auctions' })).toBeVisible();
    });

    test('artworks page renders', async ({ page }) => {
        await page.goto('/artworks');
        await expect(page.getByRole('heading', { name: 'Artworks' })).toBeVisible();
    });

    test('auctions page renders', async ({ page }) => {
        await page.goto('/auctions');
        await expect(page.getByRole('heading', { name: 'Auctions' })).toBeVisible();
    });

    test('login page renders', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByLabel('Password')).toBeVisible();
    });

    test('404 page shows on unknown route', async ({ page }) => {
        await page.goto('/does-not-exist-xyz');
        await expect(page.getByText('404')).toBeVisible();
    });
});
