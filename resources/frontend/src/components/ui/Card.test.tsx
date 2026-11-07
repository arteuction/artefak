/**
 * Phase 177 — Card, CardHeader, CardTitle component tests.
 *
 * Tests: renders children, padding prop, className passthrough, sub-components.
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import Card, { CardHeader, CardTitle } from './Card';

describe('Card', () => {
    it('renders its children', () => {
        render(<Card>Card content</Card>);
        expect(screen.getByText('Card content')).toBeInTheDocument();
    });

    it('applies padding class by default', () => {
        const { container } = render(<Card>Content</Card>);
        expect(container.firstChild as Element).toHaveClass('p-5');
    });

    it('omits padding class when padding=false', () => {
        const { container } = render(<Card padding={false}>Content</Card>);
        expect(container.firstChild as Element).not.toHaveClass('p-5');
    });

    it('passes through extra className', () => {
        const { container } = render(<Card className="extra-class">Content</Card>);
        expect(container.firstChild as Element).toHaveClass('extra-class');
    });

    it('passes through HTML div attributes', () => {
        const { container } = render(<Card data-testid="my-card">Content</Card>);
        expect(container.querySelector('[data-testid="my-card"]')).toBeInTheDocument();
    });
});

describe('CardHeader', () => {
    it('renders its children', () => {
        render(<CardHeader>Header content</CardHeader>);
        expect(screen.getByText('Header content')).toBeInTheDocument();
    });

    it('applies mb-4 class', () => {
        const { container } = render(<CardHeader>Header</CardHeader>);
        expect(container.firstChild as Element).toHaveClass('mb-4');
    });

    it('passes through extra className', () => {
        const { container } = render(<CardHeader className="extra">Header</CardHeader>);
        expect(container.firstChild as Element).toHaveClass('extra');
    });
});

describe('CardTitle', () => {
    it('renders its children as h3', () => {
        render(<CardTitle>My Title</CardTitle>);
        expect(screen.getByRole('heading', { name: 'My Title', level: 3 })).toBeInTheDocument();
    });

    it('applies semibold text class', () => {
        render(<CardTitle>Title</CardTitle>);
        expect(screen.getByRole('heading').className).toContain('font-semibold');
    });

    it('passes through extra className', () => {
        render(<CardTitle className="extra-class">Title</CardTitle>);
        expect(screen.getByRole('heading').className).toContain('extra-class');
    });
});
