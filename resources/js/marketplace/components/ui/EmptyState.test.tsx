/**
 * Phase 177 — EmptyState component tests.
 *
 * Tests: renders title, optional description, optional action node.
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import EmptyState from './EmptyState';

describe('EmptyState', () => {
    it('renders the title', () => {
        render(<EmptyState title="No artworks yet" />);
        expect(screen.getByText('No artworks yet')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
        render(<EmptyState title="Empty" description="Nothing here." />);
        expect(screen.getByText('Nothing here.')).toBeInTheDocument();
    });

    it('does not render description when omitted', () => {
        const { container } = render(<EmptyState title="Empty" />);
        // Only the title paragraph should be present; no sibling text paragraph
        const paragraphs = container.querySelectorAll('p');
        expect(paragraphs).toHaveLength(1);
        expect(paragraphs[0].textContent).toBe('Empty');
    });

    it('renders action when provided', () => {
        render(
            <EmptyState
                title="Empty"
                action={<button>Add artwork</button>}
            />
        );
        expect(screen.getByRole('button', { name: 'Add artwork' })).toBeInTheDocument();
    });

    it('does not render action wrapper when omitted', () => {
        const { container } = render(<EmptyState title="Empty" />);
        expect(container.querySelector('button')).toBeNull();
    });

    it('renders Bulgarian Cyrillic title correctly', () => {
        render(<EmptyState title="Няма произведения" />);
        expect(screen.getByText('Няма произведения')).toBeInTheDocument();
    });

    it('renders Bulgarian Cyrillic description correctly', () => {
        render(
            <EmptyState
                title="Няма произведения"
                description="Все още няма добавени творби."
            />
        );
        expect(screen.getByText('Все още няма добавени творби.')).toBeInTheDocument();
    });
});
