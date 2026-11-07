/**
 * Phase 177 — Input component tests.
 *
 * Tests: renders with label, label–input association, error state, hint,
 * required asterisk, id override, no label case, className passthrough.
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import Input from './Input';

describe('Input', () => {
    it('renders an input element', () => {
        render(<Input />);
        expect(screen.getByRole('textbox')).toBeInTheDocument();
    });

    it('renders a label when label prop is provided', () => {
        render(<Input label="Email" />);
        expect(screen.getByText('Email', { exact: false })).toBeInTheDocument();
    });

    it('associates label with input via htmlFor', () => {
        render(<Input label="Email" />);
        expect(screen.getByLabelText('Email', { exact: false })).toBeInTheDocument();
    });

    it('shows required asterisk when required=true', () => {
        const { container } = render(<Input label="Email" required />);
        expect(container.querySelector('span.text-red-500')).toBeInTheDocument();
    });

    it('does not show required asterisk when not required', () => {
        const { container } = render(<Input label="Email" />);
        expect(container.querySelector('span.text-red-500')).toBeNull();
    });

    it('shows error message when error prop is provided', () => {
        render(<Input error="Field is required" />);
        expect(screen.getByText('Field is required')).toBeInTheDocument();
    });

    it('applies error border class when error is present', () => {
        render(<Input error="Bad input" />);
        expect(screen.getByRole('textbox').className).toContain('border-red-500');
    });

    it('shows hint when provided and no error', () => {
        render(<Input hint="Enter your email" />);
        expect(screen.getByText('Enter your email')).toBeInTheDocument();
    });

    it('does not show hint when error is also present', () => {
        render(<Input error="Error" hint="Hint" />);
        expect(screen.queryByText('Hint')).toBeNull();
    });

    it('uses provided id for the input', () => {
        render(<Input id="custom-id" label="Name" />);
        expect(screen.getByRole('textbox')).toHaveAttribute('id', 'custom-id');
    });

    it('derives id from label when id is not provided', () => {
        render(<Input label="Full Name" />);
        expect(screen.getByRole('textbox')).toHaveAttribute('id', 'full-name');
    });

    it('passes through type attribute', () => {
        render(<Input type="password" />);
        const input = document.querySelector('input') as HTMLInputElement;
        expect(input).toHaveAttribute('type', 'password');
    });

    it('passes through placeholder', () => {
        render(<Input placeholder="you@example.com" />);
        expect(screen.getByPlaceholderText('you@example.com')).toBeInTheDocument();
    });

    it('passes through extra className', () => {
        render(<Input className="extra-class" />);
        expect(screen.getByRole('textbox').className).toContain('extra-class');
    });

    it('renders label with Bulgarian Cyrillic text', () => {
        render(<Input label="Имейл" />);
        expect(screen.getByText('Имейл', { exact: false })).toBeInTheDocument();
    });
});
