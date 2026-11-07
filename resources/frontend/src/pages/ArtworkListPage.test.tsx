/**
 * Integration tests for ArtworkListPage.
 *
 * Tests cover: loading, error, empty states (with and without filters),
 * artwork grid rendering via ArtworkCard, search input, medium filter,
 * and pagination controls.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import ArtworkListPage from './ArtworkListPage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet } = vi.hoisted(() => ({ mockGet: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet } };
});

// ── Wrapper ───────────────────────────────────────────────────────────────────

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ children }: { children: React.ReactNode }) {
    return (
        <QueryClientProvider client={makeQueryClient()}>
            <AuthContext.Provider value={{ user: null, loading: false, refresh: async () => {} }}>
                <MemoryRouter>
                    {children}
                </MemoryRouter>
            </AuthContext.Provider>
        </QueryClientProvider>
    );
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

function makeArtwork(id: number, overrides: Record<string, unknown> = {}) {
    return {
        id,
        title: `Artwork ${id}`,
        slug: `artwork-${id}`,
        status: 'listed',
        medium: 'painting',
        dimensions: null,
        year_created: 2024,
        is_original: true,
        edition_number: null,
        edition_total: null,
        description: null,
        currency: 'EUR',
        artist: { id: 1, name: 'Test Artist', profile_slug: 'test-artist' },
        ...overrides,
    };
}

function makePaginatedResponse(
    items: ReturnType<typeof makeArtwork>[],
    page = 1,
    lastPage = 1,
) {
    return {
        data: items,
        meta: { current_page: page, last_page: lastPage, per_page: 16, total: items.length },
        links: {},
    };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading and error states ───────────────────────────────────────────────

describe('ArtworkListPage — loading and error', () => {
    it('shows a spinner while loading', async () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows error message on API failure', async () => {
        mockGet.mockRejectedValue(new Error('Network error'));
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/failed to load artworks/i)).toBeInTheDocument());
    });
});

// ── 2. Empty states ───────────────────────────────────────────────────────────

describe('ArtworkListPage — empty state', () => {
    it('shows generic empty state when no artworks exist and no filters applied', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no artworks found/i)).toBeInTheDocument());
        expect(screen.getByText(/artworks will appear here/i)).toBeInTheDocument();
    });

    it('shows filter-specific empty state after searching', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makePaginatedResponse([]) }) // initial load
            .mockResolvedValueOnce({ data: makePaginatedResponse([]) }); // after search

        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Artworks' }));

        fireEvent.change(screen.getByPlaceholderText(/search artworks/i), {
            target: { value: 'Sofia' },
        });

        await waitFor(() =>
            expect(screen.getByText(/try different search terms/i)).toBeInTheDocument()
        );
    });
});

// ── 3. Artwork list rendering ─────────────────────────────────────────────────

describe('ArtworkListPage — list rendering', () => {
    it('renders the Artworks heading', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Artworks' })).toBeInTheDocument());
    });

    it('renders artwork cards for each artwork', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeArtwork(1), makeArtwork(2), makeArtwork(3)]),
        });
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Artwork 1')).toBeInTheDocument());
        expect(screen.getByText('Artwork 2')).toBeInTheDocument();
        expect(screen.getByText('Artwork 3')).toBeInTheDocument();
    });

    it('renders search input and medium filter', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Artworks' }));
        expect(screen.getByPlaceholderText(/search artworks/i)).toBeInTheDocument();
        expect(screen.getByRole('combobox')).toBeInTheDocument(); // medium select
    });
});

// ── 4. Filtering ──────────────────────────────────────────────────────────────

describe('ArtworkListPage — filtering', () => {
    it('sends search param when text is entered', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makePaginatedResponse([makeArtwork(1)]) })
            .mockResolvedValueOnce({ data: makePaginatedResponse([makeArtwork(1)]) });

        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));

        fireEvent.change(screen.getByPlaceholderText(/search artworks/i), {
            target: { value: 'Sofia' },
        });

        await waitFor(() => {
            const [, call] = mockGet.mock.calls;
            expect(call[1]?.params?.search).toBe('Sofia');
        });
    });

    it('sends medium param when filter is changed', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makePaginatedResponse([makeArtwork(1)]) })
            .mockResolvedValueOnce({ data: makePaginatedResponse([makeArtwork(1)]) });

        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));

        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'painting' } });

        await waitFor(() => {
            const [, call] = mockGet.mock.calls;
            expect(call[1]?.params?.medium).toBe('painting');
        });
    });

    it('resets to page 1 when search changes', async () => {
        // Start on page 2
        mockGet
            .mockResolvedValueOnce({
                data: makePaginatedResponse([makeArtwork(1)], 2, 3),
            })
            .mockResolvedValueOnce({
                data: makePaginatedResponse([makeArtwork(2)], 1, 1),
            });

        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));

        fireEvent.change(screen.getByPlaceholderText(/search artworks/i), {
            target: { value: 'query' },
        });

        await waitFor(() => {
            const [, call] = mockGet.mock.calls;
            expect(call[1]?.params?.page).toBe(1);
        });
    });
});

// ── 5. Pagination ─────────────────────────────────────────────────────────────

describe('ArtworkListPage — pagination', () => {
    it('hides pagination when there is only one page', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeArtwork(1)], 1, 1),
        });
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));
        expect(screen.queryByRole('button', { name: /previous/i })).not.toBeInTheDocument();
    });

    it('shows pagination controls on multi-page results', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeArtwork(1)], 1, 4),
        });
        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));
        expect(screen.getByRole('button', { name: /previous/i })).toBeDisabled();
        expect(screen.getByRole('button', { name: /next/i })).toBeInTheDocument();
    });

    it('clicking Next fetches page 2 and shows updated artwork', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makePaginatedResponse([makeArtwork(1)], 1, 2) })
            .mockResolvedValueOnce({ data: makePaginatedResponse([makeArtwork(2)], 2, 2) });

        render(<Wrapper><ArtworkListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork 1'));
        fireEvent.click(screen.getByRole('button', { name: /next/i }));

        await waitFor(() => expect(screen.getByText('Artwork 2')).toBeInTheDocument());
        expect(screen.getByText(/2\s*\/\s*2/)).toBeInTheDocument();
    });
});
