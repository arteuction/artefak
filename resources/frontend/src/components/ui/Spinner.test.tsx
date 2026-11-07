/**
 * Phase 177 — Spinner component tests.
 *
 * Tests: role=status accessible role, aria-label, three sizes, className passthrough.
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import Spinner from './Spinner';

describe('Spinner', () => {
    it('has role="status"', () => {
        render(<Spinner />);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('has aria-label "Loading"', () => {
        render(<Spinner />);
        expect(screen.getByRole('status')).toHaveAttribute('aria-label', 'Loading');
    });

    it('applies sm size class', () => {
        render(<Spinner size="sm" />);
        const cls = screen.getByRole('status').getAttribute('class') ?? '';
        expect(cls).toContain('h-4');
        expect(cls).toContain('w-4');
    });

    it('applies md size class by default', () => {
        render(<Spinner />);
        const cls = screen.getByRole('status').getAttribute('class') ?? '';
        expect(cls).toContain('h-6');
        expect(cls).toContain('w-6');
    });

    it('applies lg size class', () => {
        render(<Spinner size="lg" />);
        const cls = screen.getByRole('status').getAttribute('class') ?? '';
        expect(cls).toContain('h-10');
        expect(cls).toContain('w-10');
    });

    it('passes through extra className', () => {
        render(<Spinner className="extra-class" />);
        const cls = screen.getByRole('status').getAttribute('class') ?? '';
        expect(cls).toContain('extra-class');
    });
});
