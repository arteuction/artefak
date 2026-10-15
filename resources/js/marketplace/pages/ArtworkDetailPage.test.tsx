/**
 * Integration tests for ArtworkDetailPage.
 *
 * HTTP is mocked via vi.mock('@/lib/api'). Auth context is injected via Wrapper.
 * Tests cover: loading, not-found, artwork metadata display, status variants,
 * sell-now offer modal flow, active-offer state, and unauthenticated CTA.
 */

import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import ArtworkDetailPage from './ArtworkDetailPage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet, mockPost } = vi.hoisted(() => ({
    mockGet: vi.fn(),
    mockPost: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet, post: mockPost } };
});

// ── Wrapper ───────────────────────────────────────────────────────────────────

const BUYER: AuthUser = { id: 10, name: 'Buyer', email: 'b@test.com', role: 'buyer' };
const ARTIST: AuthUser = { id: 20, name: 'Artist', email: 'a@test.com', role: 'artist' };

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ user = BUYER, slug = 'sunrise-sofia', children }: {
    user?: AuthUser | null;
    slug?: string;
    children: React.ReactNode;
}) {
    return (
        <QueryClientProvider client={makeQueryClient()}>
            <AuthContext.Provider value={{ user, loading: false, refresh: async () => {} }}>
                <MemoryRouter initialEntries={[`/artworks/${slug}`]}>
                    <Routes>
                        <Route path="/artworks/:slug" element={children} />
                    </Routes>
                </MemoryRouter>
            </AuthContext.Provider>
        </QueryClientProvider>
    );
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

function makeArtwork(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        title: 'Sunrise Over Sofia',
        slug: 'sunrise-sofia',
        status: 'listed',
        medium: 'Oil on canvas',
        dimensions: '80 × 60 cm',
        year_created: 2024,
        is_original: true,
        edition_number: null,
        edition_total: null,
        description: 'A beautiful morning scene.',
        artist: {
            id: 20,
            name: 'Ivan Petrov',
            profile_slug: 'ivan-petrov',
        },
        currency: 'EUR',
        ...overrides,
    };
}

const ACTIVE_LOT = { id: 55, status: 'active' };
const SOLD_LOT   = { id: 56, status: 'sold' };

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading and error states ───────────────────────────────────────────────

describe('ArtworkDetailPage — loading and error', () => {
    it('shows a spinner while loading', async () => {
        mockGet.mockReturnValue(new Promise(() => {})); // never resolves
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument(); // Spinner
    });

    it('shows not-found message on API error', async () => {
        mockGet.mockRejectedValue(new Error('Not Found'));
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/not found/i)).toBeInTheDocument());
    });
});

// ── 2. Artwork metadata ───────────────────────────────────────────────────────

describe('ArtworkDetailPage — metadata display', () => {
    it('renders title and artist name', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Sunrise Over Sofia' })).toBeInTheDocument());
        expect(screen.getByText('Ivan Petrov')).toBeInTheDocument();
    });

    it('renders medium, dimensions, year and edition metadata', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByText('Oil on canvas')).toBeInTheDocument();
        expect(screen.getByText('80 × 60 cm')).toBeInTheDocument();
        expect(screen.getByText('2024')).toBeInTheDocument();
        expect(screen.getByText('Original')).toBeInTheDocument();
    });

    it('renders description', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByText('A beautiful morning scene.')).toBeInTheDocument();
    });

    it('renders artist profile link when profile_slug is set', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork() });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        const artistLink = screen.getByRole('link', { name: 'Ivan Petrov' });
        expect(artistLink).toHaveAttribute('href', '/artists/ivan-petrov');
    });

    it('shows edition fraction when artwork is not original', async () => {
        mockGet.mockResolvedValue({
            data: makeArtwork({ is_original: false, edition_number: 3, edition_total: 10 }),
        });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByText('3 / 10')).toBeInTheDocument();
    });

    it('renders Bulgarian Cyrillic title correctly', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ title: 'Изгрев над София' }) });
        render(<Wrapper slug="izgrев-sofia"><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Изгрев над София' })).toBeInTheDocument());
    });
});

// ── 3. Status variants ────────────────────────────────────────────────────────

describe('ArtworkDetailPage — status badge variants', () => {
    it('shows "For Sale" badge when listed', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'listed' }) });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByText('For Sale')).toBeInTheDocument();
    });

    it('shows "Sold" badge and sold message when sold', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'sold' }) });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByText('Sold')).toBeInTheDocument();
        expect(screen.getByText(/This artwork has been sold/)).toBeInTheDocument();
    });

    it('shows "In Auction" badge when in_auction', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'in_auction' }) });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByText('In Auction')).toBeInTheDocument();
    });
});

// ── 4. Make-an-offer CTA ──────────────────────────────────────────────────────

describe('ArtworkDetailPage — offer CTA', () => {
    it('shows "Make an offer" button for buyer on listed artwork with active lot', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makeArtwork({ status: 'listed' }) })
            .mockResolvedValueOnce({ data: { data: [ACTIVE_LOT] } })    // lots
            .mockResolvedValueOnce({ data: { data: [] } });              // existing offers (empty)
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('button', { name: /make an offer/i })).toBeInTheDocument());
    });

    it('shows "Sign in" prompt for unauthenticated user on listed artwork', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'listed' }) });
        render(<Wrapper user={null}><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.getByRole('link', { name: /sign in/i })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /make an offer/i })).not.toBeInTheDocument();
    });

    it('shows active offer status instead of "Make an offer" when offer exists', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makeArtwork({ status: 'listed' }) })
            .mockResolvedValueOnce({ data: { data: [ACTIVE_LOT] } })
            .mockResolvedValueOnce({ data: { data: [{ id: 1, status: 'submitted' }] } });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/active offer/i)).toBeInTheDocument());
        expect(screen.queryByRole('button', { name: /make an offer/i })).not.toBeInTheDocument();
    });

    it('does not show offer button for artist role', async () => {
        mockGet.mockResolvedValue({ data: makeArtwork({ status: 'listed' }) });
        render(<Wrapper user={ARTIST}><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.queryByRole('button', { name: /make an offer/i })).not.toBeInTheDocument();
    });

    it('does not show offer button when artwork has no active lot', async () => {
        mockGet
            .mockResolvedValueOnce({ data: makeArtwork({ status: 'listed' }) })
            .mockResolvedValueOnce({ data: { data: [SOLD_LOT] } });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Sunrise Over Sofia' }));
        expect(screen.queryByRole('button', { name: /make an offer/i })).not.toBeInTheDocument();
    });
});

// ── 5. Offer modal ────────────────────────────────────────────────────────────

describe('ArtworkDetailPage — offer modal', () => {
    async function openModal() {
        mockGet
            .mockResolvedValueOnce({ data: makeArtwork({ status: 'listed' }) })
            .mockResolvedValueOnce({ data: { data: [ACTIVE_LOT] } })
            .mockResolvedValueOnce({ data: { data: [] } });
        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /make an offer/i }));
        fireEvent.click(screen.getByRole('button', { name: /make an offer/i }));
        await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    }

    it('opens the offer modal with price input', async () => {
        await openModal();
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByLabelText(/offer price/i)).toBeInTheDocument();
    });

    it('cancel button closes the modal', async () => {
        await openModal();
        fireEvent.click(screen.getByRole('button', { name: /cancel/i }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('submit button is disabled when price is empty', async () => {
        await openModal();
        expect(screen.getByRole('button', { name: /submit offer/i })).toBeDisabled();
    });

    it('submits offer and closes modal on success', async () => {
        mockPost.mockResolvedValue({ data: { id: 99, status: 'submitted' } });
        // Re-mock get to return empty offers after submission
        mockGet
            .mockResolvedValueOnce({ data: makeArtwork({ status: 'listed' }) })
            .mockResolvedValueOnce({ data: { data: [ACTIVE_LOT] } })
            .mockResolvedValueOnce({ data: { data: [] } })
            .mockResolvedValue({ data: { data: [] } }); // invalidation refetch

        render(<Wrapper><ArtworkDetailPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /make an offer/i }));
        fireEvent.click(screen.getByRole('button', { name: /make an offer/i }));
        await waitFor(() => screen.getByRole('dialog'));

        fireEvent.change(screen.getByLabelText(/offer price/i), { target: { value: '500' } });
        fireEvent.click(screen.getByRole('button', { name: /submit offer/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(
            `/art-lots/${ACTIVE_LOT.id}/sell-now-offers`,
            expect.objectContaining({ offered_price_cents: 50000 }),
        ));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('shows error message when offer submission fails', async () => {
        mockPost.mockRejectedValue(new Error('Server error'));
        await openModal();

        fireEvent.change(screen.getByLabelText(/offer price/i), { target: { value: '500' } });
        fireEvent.click(screen.getByRole('button', { name: /submit offer/i }));

        await waitFor(() => expect(screen.getByText(/failed to submit offer/i)).toBeInTheDocument());
        // Modal stays open after error
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
