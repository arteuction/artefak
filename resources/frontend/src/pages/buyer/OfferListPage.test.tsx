/**
 * Integration tests for OfferListPage (buyer's offer dashboard).
 *
 * Tests cover: unauthenticated redirect, loading, empty state, offer list
 * rendering, status badges, counter-offer row (accept/decline), PayNow
 * button and checkout error.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import OfferListPage from './OfferListPage';

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

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function Wrapper({ user = BUYER as AuthUser | null, children }: {
    user?: AuthUser | null;
    children: React.ReactNode;
}) {
    return (
        <QueryClientProvider client={makeQueryClient()}>
            <AuthContext.Provider value={{ user, loading: false, refresh: async () => {} }}>
                <MemoryRouter initialEntries={['/offers']}>
                    {children}
                </MemoryRouter>
            </AuthContext.Provider>
        </QueryClientProvider>
    );
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

function makeOffer(id: number, status: string, overrides: Record<string, unknown> = {}) {
    return {
        id,
        status,
        offered_price_cents: 50_000,
        counter_price_cents: null,
        currency: 'EUR',
        expires_at: null,
        artwork: { id: 1, title: `Artwork for offer ${id}`, slug: `artwork-${id}` },
        ...overrides,
    };
}

function makePaginatedResponse(items: ReturnType<typeof makeOffer>[]) {
    return {
        data: items,
        meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length },
        links: {},
    };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Unauthenticated state ──────────────────────────────────────────────────

describe('OfferListPage — unauthenticated', () => {
    it('shows sign-in prompt for unauthenticated user', async () => {
        render(<Wrapper user={null}><OfferListPage /></Wrapper>);
        expect(screen.getByText(/sign in to view your offers/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /sign in/i })).toBeInTheDocument();
    });

    it('does not fetch offers when unauthenticated', () => {
        render(<Wrapper user={null}><OfferListPage /></Wrapper>);
        expect(mockGet).not.toHaveBeenCalled();
    });
});

// ── 2. Loading and empty states ───────────────────────────────────────────────

describe('OfferListPage — loading and empty', () => {
    it('shows a spinner while loading', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><OfferListPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows empty state with browse link when no offers', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no offers yet/i)).toBeInTheDocument());
        expect(screen.getByRole('link', { name: /browse artworks/i })).toBeInTheDocument();
    });
});

// ── 3. Offer list rendering ───────────────────────────────────────────────────

describe('OfferListPage — offer list', () => {
    it('renders the "My offers" heading', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: /my offers/i })).toBeInTheDocument());
    });

    it('renders artwork title as a link to the artwork detail', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([makeOffer(1, 'submitted')]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork for offer 1'));
        const artworkLink = screen.getByRole('link', { name: 'Artwork for offer 1' });
        expect(artworkLink).toHaveAttribute('href', '/artworks/artwork-1');
    });

    it('shows offered price in EUR', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([makeOffer(1, 'submitted')]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/€500\.00/)).toBeInTheDocument());
    });

    it('renders status badges for each status', async () => {
        const statuses = ['submitted', 'countered', 'accepted', 'rejected', 'paid'];
        mockGet.mockResolvedValue({
            data: makePaginatedResponse(statuses.map((s, i) => makeOffer(i + 1, s))),
        });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Submitted'));
        expect(screen.getByText('Countered')).toBeInTheDocument();
        expect(screen.getByText('Accepted')).toBeInTheDocument();
        expect(screen.getByText('Rejected')).toBeInTheDocument();
        expect(screen.getByText('Paid')).toBeInTheDocument();
    });

    it('shows "Payment complete" text for paid offer', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([makeOffer(1, 'paid')]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/payment complete/i)).toBeInTheDocument());
    });

    it('shows expiry date on submitted offer with expires_at', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([
                makeOffer(1, 'submitted', { expires_at: '2026-12-01T00:00:00Z' }),
            ]),
        });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Artwork for offer 1'));
        expect(screen.getByText(/expires/i)).toBeInTheDocument();
    });
});

// ── 4. Counter-offer row ──────────────────────────────────────────────────────

describe('OfferListPage — counter-offer row', () => {
    const counteredOffer = makeOffer(5, 'countered', { counter_price_cents: 75_000 });

    it('shows counter-offer price and action buttons', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([counteredOffer]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByText(/counter-offer received/i));
        expect(screen.getByText(/€750\.00/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /accept counter-offer/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /decline/i })).toBeInTheDocument();
    });

    it('calls accept endpoint when accept is clicked', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([counteredOffer]) });
        mockPost.mockResolvedValue({ data: {} });

        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /accept counter-offer/i }));
        fireEvent.click(screen.getByRole('button', { name: /accept counter-offer/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/sell-now-offers/${counteredOffer.id}/accept`));
    });

    it('calls reject endpoint when decline is clicked', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([counteredOffer]) });
        mockPost.mockResolvedValue({ data: {} });

        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /decline/i }));
        fireEvent.click(screen.getByRole('button', { name: /decline/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(`/sell-now-offers/${counteredOffer.id}/reject`));
    });

    it('shows error if accept fails', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([counteredOffer]) });
        mockPost.mockRejectedValue(new Error('Server error'));

        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /accept counter-offer/i }));
        fireEvent.click(screen.getByRole('button', { name: /accept counter-offer/i }));

        await waitFor(() => expect(screen.getByText(/action failed/i)).toBeInTheDocument());
    });
});

// ── 5. Pay Now button ─────────────────────────────────────────────────────────

describe('OfferListPage — Pay Now button', () => {
    it('shows Pay Now button for accepted offer', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([makeOffer(3, 'accepted')]) });
        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('button', { name: /pay now/i })).toBeInTheDocument());
    });

    it('calls checkout-session endpoint when Pay Now is clicked', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([makeOffer(3, 'accepted')]) });
        // Prevent window.location.href assignment from causing test errors
        const originalLocation = window.location;
        Object.defineProperty(window, 'location', { writable: true, value: { href: '' } });
        mockPost.mockResolvedValue({ data: { url: 'https://checkout.stripe.com/test' } });

        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /pay now/i }));
        fireEvent.click(screen.getByRole('button', { name: /pay now/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/sell-now-offers/3/checkout-session'));
        Object.defineProperty(window, 'location', { writable: true, value: originalLocation });
    });

    it('shows checkout error when session creation fails', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([makeOffer(3, 'accepted')]) });
        mockPost.mockRejectedValue(new Error('Stripe error'));

        render(<Wrapper><OfferListPage /></Wrapper>);
        await waitFor(() => screen.getByRole('button', { name: /pay now/i }));
        fireEvent.click(screen.getByRole('button', { name: /pay now/i }));

        await waitFor(() => expect(screen.getByText(/could not start checkout/i)).toBeInTheDocument());
    });
});
