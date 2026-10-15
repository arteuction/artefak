import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/marketplace/main.tsx',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': `${import.meta.dirname}/resources/js/marketplace`,
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/marketplace/test/setup.ts'],
        include: ['resources/js/marketplace/**/*.test.{ts,tsx}'],
        coverage: {
            provider: 'v8',
            include: ['resources/js/marketplace/**'],
        },
        // Provide a stable base URL so imageUrl() returns real strings in tests.
        // Production VITE_STORAGE_URL is set in .env.production.
        env: {
            VITE_STORAGE_URL: 'https://test-cdn.example',
        },
    },
});
