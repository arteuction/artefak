/**
 * Phase 178 — useDocTitle hook tests.
 *
 * Tests: sets document.title with suffix, does nothing for null/undefined,
 * updates when title changes, restores previous title on unmount.
 */

import { renderHook } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { useDocTitle } from './useDocTitle';

describe('useDocTitle', () => {
    const originalTitle = document.title;

    beforeEach(() => {
        document.title = 'Original';
    });

    afterEach(() => {
        document.title = originalTitle;
    });

    it('sets document.title with " — ARTeuCtion" suffix', () => {
        renderHook(() => useDocTitle('Artworks'));
        expect(document.title).toBe('Artworks — ARTeuCtion');
    });

    it('does not change title when null is passed', () => {
        renderHook(() => useDocTitle(null));
        expect(document.title).toBe('Original');
    });

    it('does not change title when undefined is passed', () => {
        renderHook(() => useDocTitle(undefined));
        expect(document.title).toBe('Original');
    });

    it('restores the previous title on unmount', () => {
        document.title = 'Before';
        const { unmount } = renderHook(() => useDocTitle('Artworks'));
        expect(document.title).toBe('Artworks — ARTeuCtion');
        unmount();
        expect(document.title).toBe('Before');
    });

    it('updates title when value changes', () => {
        let title: string | null = 'First';
        const { rerender } = renderHook(() => useDocTitle(title));
        expect(document.title).toBe('First — ARTeuCtion');

        title = 'Second';
        rerender();
        expect(document.title).toBe('Second — ARTeuCtion');
    });

    it('sets title correctly with Bulgarian Cyrillic', () => {
        renderHook(() => useDocTitle('Произведения'));
        expect(document.title).toBe('Произведения — ARTeuCtion');
    });
});
