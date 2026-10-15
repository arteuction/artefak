/**
 * Phase 177 — Button component tests.
 *
 * Tests: renders children, variants, sizes, loading state, disabled, click handler,
 * className passthrough, disabled+loading both set disabled attribute.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import Button from './Button';

describe('Button', () => {
    it('renders its children', () => {
        render(<Button>Click me</Button>);
        expect(screen.getByRole('button', { name: 'Click me' })).toBeInTheDocument();
    });

    it('calls onClick when clicked', async () => {
        const handler = vi.fn();
        render(<Button onClick={handler}>Click</Button>);
        await userEvent.click(screen.getByRole('button'));
        expect(handler).toHaveBeenCalledTimes(1);
    });

    it('does not call onClick when disabled', async () => {
        const handler = vi.fn();
        render(<Button disabled onClick={handler}>Click</Button>);
        await userEvent.click(screen.getByRole('button'));
        expect(handler).not.toHaveBeenCalled();
    });

    it('is disabled when disabled prop is true', () => {
        render(<Button disabled>Disabled</Button>);
        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('is disabled when loading=true', () => {
        render(<Button loading>Loading</Button>);
        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('shows a spinner svg when loading', () => {
        const { container } = render(<Button loading>Save</Button>);
        const svgs = container.querySelectorAll('svg');
        expect(svgs.length).toBeGreaterThan(0);
    });

    it('applies primary variant classes by default', () => {
        render(<Button>Primary</Button>);
        expect(screen.getByRole('button').className).toContain('bg-[var(--color-accent)]');
    });

    it('applies secondary variant classes', () => {
        render(<Button variant="secondary">Secondary</Button>);
        expect(screen.getByRole('button').className).toContain('border');
    });

    it('applies ghost variant classes', () => {
        render(<Button variant="ghost">Ghost</Button>);
        expect(screen.getByRole('button').className).toContain('bg-transparent');
    });

    it('applies danger variant classes', () => {
        render(<Button variant="danger">Delete</Button>);
        expect(screen.getByRole('button').className).toContain('bg-red-600');
    });

    it('applies sm size classes', () => {
        render(<Button size="sm">Small</Button>);
        expect(screen.getByRole('button').className).toContain('text-xs');
    });

    it('applies md size classes by default', () => {
        render(<Button>Medium</Button>);
        expect(screen.getByRole('button').className).toContain('text-sm');
    });

    it('applies lg size classes', () => {
        render(<Button size="lg">Large</Button>);
        expect(screen.getByRole('button').className).toContain('text-base');
    });

    it('passes through extra className', () => {
        render(<Button className="extra-class">Test</Button>);
        expect(screen.getByRole('button').className).toContain('extra-class');
    });

    it('passes through type attribute', () => {
        render(<Button type="submit">Submit</Button>);
        expect(screen.getByRole('button')).toHaveAttribute('type', 'submit');
    });
});
