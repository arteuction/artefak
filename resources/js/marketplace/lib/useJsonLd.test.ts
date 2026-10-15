/**
 * Phase 178 — useJsonLd hook tests.
 *
 * Tests: injects script tag in head, uses application/ld+json type,
 * correct id from @type, serializes data as JSON, removes on unmount,
 * does nothing when null passed, updates script when data changes.
 */

import { renderHook } from '@testing-library/react';
import { describe, it, expect, beforeEach } from 'vitest';
import { useJsonLd } from './useJsonLd';

function getJsonLdScript(id: string): HTMLScriptElement | null {
    return document.head.querySelector(`#${id}`) as HTMLScriptElement | null;
}

describe('useJsonLd', () => {
    beforeEach(() => {
        // Clean up any lingering script tags
        document.head.querySelectorAll('script[type="application/ld+json"]').forEach(el => el.remove());
    });

    it('injects a script tag into document.head', () => {
        renderHook(() => useJsonLd({ '@type': 'Product', name: 'Test' }));
        expect(document.head.querySelector('script[type="application/ld+json"]')).not.toBeNull();
    });

    it('sets type to application/ld+json', () => {
        renderHook(() => useJsonLd({ '@type': 'Product', name: 'Test' }));
        const script = document.head.querySelector('script');
        expect(script?.type).toBe('application/ld+json');
    });

    it('sets id from @type field', () => {
        renderHook(() => useJsonLd({ '@type': 'VisualArtwork', name: 'Painting' }));
        expect(getJsonLdScript('jsonld-VisualArtwork')).not.toBeNull();
    });

    it('uses "schema" as fallback id when @type is absent', () => {
        renderHook(() => useJsonLd({ name: 'No type' }));
        expect(getJsonLdScript('jsonld-schema')).not.toBeNull();
    });

    it('serializes data as valid JSON in the script text', () => {
        const data = { '@type': 'Organization', name: 'ARTeuCtion' };
        renderHook(() => useJsonLd(data));
        const script = getJsonLdScript('jsonld-Organization');
        expect(script).not.toBeNull();
        const parsed = JSON.parse(script!.text);
        expect(parsed.name).toBe('ARTeuCtion');
        expect(parsed['@type']).toBe('Organization');
    });

    it('removes the script tag on unmount', () => {
        const { unmount } = renderHook(() => useJsonLd({ '@type': 'Product' }));
        expect(getJsonLdScript('jsonld-Product')).not.toBeNull();
        unmount();
        expect(getJsonLdScript('jsonld-Product')).toBeNull();
    });

    it('does nothing when null is passed', () => {
        renderHook(() => useJsonLd(null));
        expect(document.head.querySelector('script[type="application/ld+json"]')).toBeNull();
    });

    it('handles Bulgarian Cyrillic in data values', () => {
        const data = { '@type': 'VisualArtwork', name: 'Изгрев над София' };
        renderHook(() => useJsonLd(data));
        const script = getJsonLdScript('jsonld-VisualArtwork');
        expect(JSON.parse(script!.text).name).toBe('Изгрев над София');
    });
});
