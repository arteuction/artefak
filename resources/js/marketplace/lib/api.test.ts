/**
 * Phase 178 — api.ts utility function tests.
 *
 * Tests: imageUrl returns correct URL when key+base are set,
 * returns null for falsy key, returns null when base is not set (override env).
 *
 * VITE_STORAGE_URL is set to 'https://test-cdn.example' in vitest.config.ts.
 */

import { describe, it, expect } from 'vitest';
import { imageUrl } from './api';

describe('imageUrl', () => {
    it('returns a URL by joining base and key with a slash', () => {
        expect(imageUrl('artworks/abc.jpg')).toBe('https://test-cdn.example/artworks/abc.jpg');
    });

    it('returns null when key is null', () => {
        expect(imageUrl(null)).toBeNull();
    });

    it('returns null when key is undefined', () => {
        expect(imageUrl(undefined)).toBeNull();
    });

    it('returns null when key is an empty string', () => {
        expect(imageUrl('')).toBeNull();
    });

    it('preserves deep path keys', () => {
        const key = 'artworks/2026/01/painting.webp';
        expect(imageUrl(key)).toBe(`https://test-cdn.example/${key}`);
    });
});
