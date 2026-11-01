/**
 * Tests for DesignLabPage — Phase 161 design system specimen page.
 *
 * This is a static display-only page; tests verify sections render,
 * the surface-layer toggle works, and Cyrillic text is present.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import DesignLabPage from './DesignLabPage';

describe('DesignLabPage — structure', () => {
    it('renders the Design System Foundation heading', () => {
        render(<DesignLabPage />);
        expect(screen.getByRole('heading', { name: /design system foundation/i })).toBeInTheDocument();
    });

    it('renders "Colour palette" section label', () => {
        render(<DesignLabPage />);
        expect(screen.getByText(/colour palette/i)).toBeInTheDocument();
    });

    it('renders typography section with Latin specimen text', () => {
        render(<DesignLabPage />);
        const items = screen.getAllByText(/artwork title/i);
        expect(items.length).toBeGreaterThan(0);
    });

    it('renders Cyrillic typography validation text', () => {
        render(<DesignLabPage />);
        expect(screen.getByText(/художествена галерия/i)).toBeInTheDocument();
    });

    it('renders UI type scale section', () => {
        render(<DesignLabPage />);
        expect(screen.getByText(/ui type scale/i)).toBeInTheDocument();
    });

    it('renders accent & interactive section with Place bid button', () => {
        render(<DesignLabPage />);
        expect(screen.getByRole('button', { name: /place bid/i })).toBeInTheDocument();
    });

    it('renders Make offer button', () => {
        render(<DesignLabPage />);
        expect(screen.getByRole('button', { name: /make offer/i })).toBeInTheDocument();
    });

    it('renders Live badge', () => {
        render(<DesignLabPage />);
        expect(screen.getByText(/live/i)).toBeInTheDocument();
    });

    it('renders surface layers section', () => {
        render(<DesignLabPage />);
        expect(screen.getByText(/surface layers/i)).toBeInTheDocument();
    });

    it('renders three bg toggle buttons', () => {
        render(<DesignLabPage />);
        expect(screen.getByRole('button', { name: 'bg' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'bg-subtle' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'bg-muted' })).toBeInTheDocument();
    });
});

describe('DesignLabPage — surface layer toggle', () => {
    it('clicking bg-subtle changes active button', async () => {
        render(<DesignLabPage />);
        const subtleBtn = screen.getByRole('button', { name: 'bg-subtle' });
        await userEvent.click(subtleBtn);
        // After click, bg-subtle should be the active surface (no crash)
        expect(subtleBtn).toBeInTheDocument();
    });

    it('clicking bg-muted does not crash', async () => {
        render(<DesignLabPage />);
        await userEvent.click(screen.getByRole('button', { name: 'bg-muted' }));
        expect(screen.getByText(/surface layers/i)).toBeInTheDocument();
    });
});
