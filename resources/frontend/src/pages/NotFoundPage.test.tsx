/**
 * Tests for NotFoundPage — 404 page shown when no route matches.
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import NotFoundPage from './NotFoundPage';

function Wrapper() {
    return (
        <MemoryRouter>
            <NotFoundPage />
        </MemoryRouter>
    );
}

describe('NotFoundPage', () => {
    it('renders the 404 heading', () => {
        render(<Wrapper />);
        expect(screen.getByText('404')).toBeInTheDocument();
    });

    it('renders "Page not found" heading', () => {
        render(<Wrapper />);
        expect(screen.getByRole('heading', { name: /page not found/i })).toBeInTheDocument();
    });

    it('renders descriptive message', () => {
        render(<Wrapper />);
        expect(screen.getByText(/doesn't exist/i)).toBeInTheDocument();
    });

    it('renders a Go home link pointing to /', () => {
        render(<Wrapper />);
        const link = screen.getByRole('link', { name: /go home/i });
        expect(link).toBeInTheDocument();
        expect(link).toHaveAttribute('href', '/');
    });
});
