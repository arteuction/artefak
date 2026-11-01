/**
 * Phase 177 — Badge component tests.
 *
 * Tests: renders children, default and named variants, pulse, className passthrough.
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import Badge from './Badge';

describe('Badge', () => {
    it('renders its children', () => {
        render(<Badge>Live</Badge>);
        expect(screen.getByText('Live')).toBeInTheDocument();
    });

    it('renders as a span element', () => {
        render(<Badge>Test</Badge>);
        expect(screen.getByText('Test').tagName).toBe('SPAN');
    });

    it('applies default variant classes', () => {
        render(<Badge>Default</Badge>);
        const el = screen.getByText('Default');
        expect(el.className).toContain('bg-[var(--color-bg-muted)]');
    });

    it('applies live variant classes', () => {
        render(<Badge variant="live">Live</Badge>);
        const el = screen.getByText('Live');
        expect(el.className).toContain('bg-[var(--color-live-bg)]');
    });

    it('applies sold variant classes', () => {
        render(<Badge variant="sold">Sold</Badge>);
        const el = screen.getByText('Sold');
        expect(el.className).toContain('bg-[var(--color-sold-bg)]');
    });

    it('applies draft variant classes', () => {
        render(<Badge variant="draft">Draft</Badge>);
        const el = screen.getByText('Draft');
        expect(el.className).toContain('bg-[var(--color-draft-bg)]');
    });

    it('applies auction variant classes', () => {
        render(<Badge variant="auction">Auction</Badge>);
        const el = screen.getByText('Auction');
        expect(el.className).toContain('bg-amber-50');
    });

    it('applies outline variant classes', () => {
        render(<Badge variant="outline">Outline</Badge>);
        const el = screen.getByText('Outline');
        expect(el.className).toContain('bg-transparent');
        expect(el.className).toContain('border');
    });

    it('adds animate-pulse when pulse=true', () => {
        render(<Badge pulse>Pulsing</Badge>);
        expect(screen.getByText('Pulsing').className).toContain('animate-pulse');
    });

    it('does not add animate-pulse by default', () => {
        render(<Badge>Static</Badge>);
        expect(screen.getByText('Static').className).not.toContain('animate-pulse');
    });

    it('passes through extra className', () => {
        render(<Badge className="extra-class">Test</Badge>);
        expect(screen.getByText('Test').className).toContain('extra-class');
    });
});
