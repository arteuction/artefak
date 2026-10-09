import { defineConfig, devices } from '@playwright/test';

/**
 * Phase 92 — End-to-end acceptance tests.
 * Run against the local dev server (APP_URL or http://localhost:8000).
 * CI sets PLAYWRIGHT_BASE_URL before running `npx playwright test`.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],

    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8000',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
