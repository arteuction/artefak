/**
 * Integration tests for VenueListPage (ArtMetro venues).
 *
 * HTTP mocked via vi.mock('@/lib/api').
 * Tests cover: loading, empty state, venue card link/name/city/address/
 * description, status badges (Open/Closed), multiple venues.
 */

import { render, screen, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import VenueListPage from './VenueListPage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet } = vi.hoisted(() => ({ mockGet: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet } };
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ children }: { children: React.ReactNode }) {
    return (
        <MemoryRouter>
            <QueryClientProvider client={makeQueryClient()}>
                {children}
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makeVenue(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Национална галерия',
        slug: 'natsionalna-galeriya',
        address: 'пл. „Александър Батенберг" 1',
        city: 'София',
        description: 'Главната художествена галерия на България.',
        lat: 42.697,
        lng: 23.332,
        website_url: null,
        status: 'active',
        ...overrides,
    };
}

function makePaginatedResponse(items: unknown[]) {
    return { data: { data: items, meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length } } };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading ────────────────────────────────────────────────────────────────

describe('VenueListPage — loading', () => {
    it('shows spinner while data loads', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><VenueListPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });
});

// ── 2. Empty state ────────────────────────────────────────────────────────────

describe('VenueListPage — empty state', () => {
    it('shows empty state when no venues', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no venues yet/i)).toBeInTheDocument());
    });

    it('renders page heading ArtMetro', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText(/no venues yet/i));
        expect(screen.getByRole('heading', { name: /artmetro/i })).toBeInTheDocument();
    });
});

// ── 3. Venue cards ────────────────────────────────────────────────────────────

describe('VenueListPage — venue cards', () => {
    it('renders venue name as a link to /metro/:slug', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue()]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        const link = screen.getByRole('link', { name: /национална галерия/i });
        expect(link).toHaveAttribute('href', '/metro/natsionalna-galeriya');
    });

    it('shows city', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue()]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        expect(screen.getByText('София')).toBeInTheDocument();
    });

    it('shows address', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue()]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        expect(screen.getByText(/батенберг/i)).toBeInTheDocument();
    });

    it('shows description', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue()]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        expect(screen.getByText(/главната художествена/i)).toBeInTheDocument();
    });

    it('omits city when null', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue({ city: null })]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        expect(screen.queryByText('София')).not.toBeInTheDocument();
    });

    it('renders multiple venue cards', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([
            makeVenue({ id: 1, name: 'Галерия едно', slug: 'g1' }),
            makeVenue({ id: 2, name: 'Галерия две',  slug: 'g2' }),
        ]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Галерия едно'));
        expect(screen.getByText('Галерия две')).toBeInTheDocument();
    });
});

// ── 4. Status badges ──────────────────────────────────────────────────────────

describe('VenueListPage — status badges', () => {
    it('shows Open badge for active venue', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue({ status: 'active' })]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        expect(screen.getByText('Open')).toBeInTheDocument();
    });

    it('shows Closed badge for inactive venue', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeVenue({ status: 'inactive' })]));
        render(<Wrapper><VenueListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Национална галерия'));
        expect(screen.getByText('Closed')).toBeInTheDocument();
    });
});
