/**
 * Integration tests for gallery OfferManagePage.
 *
 * HTTP mocked via vi.mock('@/lib/api'). Auth context injected via Wrapper.
 * Tests cover: unauthenticated state, loading, empty state, offer list
 * rendering, offered/counter price display, status badges, Respond toggle,
 * CounterOfferForm (send counter, accept as-is, reject), error messages.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import OfferManagePage from './OfferManagePage';

// ── Fake API ──────────────────────────────────────────────────────────────────

const { mockGet, mockPost } = vi.hoisted(() => ({
    mockGet:  vi.fn(),
    mockPost: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return { ...mod, api: { get: mockGet, post: mockPost } };
});

// ── Helpers ───────────────────────────────────────────────────────────────────

const GALLERY_USER: AuthUser = { id: 1, name: 'Gallery Manager', email: 'gm@test.com', role: 'admin' };

function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function Wrapper({ user = GALLERY_USER as AuthUser | null, children }: {
    user?: AuthUser | null;
    children: React.ReactNode;
}) {
    const ctx = { user, loading: false, refresh: vi.fn(), logout: vi.fn() };
    return (
        <MemoryRouter>
            <QueryClientProvider client={makeQueryClient()}>
                <AuthContext.Provider value={ctx}>
                    {children}
                </AuthContext.Provider>
            </QueryClientProvider>
        </MemoryRouter>
    );
}

function makeOffer(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        art_lot_id: 5,
        offered_price_cents: 200_000,
        counter_price_cents: null,
        agreed_price_cents:  null,
        currency: 'EUR',
        status: 'submitted',
        notes: null,
        expires_at: null,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        artwork: { id: 10, title: 'Черноморски залез', slug: 'chernomorski-zalez' },
        ...overrides,
    };
}

function makePaginatedResponse(items: unknown[]) {
    return { data: { data: items, meta: { current_page: 1, last_page: 1, per_page: 15, total: items.length } } };
}

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Auth guard ─────────────────────────────────────────────────────────────

describe('OfferManagePage — auth guard', () => {
    it('shows sign-in prompt when unauthenticated', () => {
        render(<Wrapper user={null}><OfferManagePage /></Wrapper>);
        expect(screen.getByText(/sign in to manage offers/i)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /sign in/i })).toBeInTheDocument();
    });

    it('does not call API when unauthenticated', () => {
        render(<Wrapper user={null}><OfferManagePage /></Wrapper>);
        expect(mockGet).not.toHaveBeenCalled();
    });
});

// ── 2. Loading ────────────────────────────────────────────────────────────────

describe('OfferManagePage — loading', () => {
    it('shows spinner while offers load', () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });
});

// ── 3. Empty state ────────────────────────────────────────────────────────────

describe('OfferManagePage — empty state', () => {
    it('shows empty state when no offers', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no offers/i)).toBeInTheDocument());
    });

    it('renders page heading', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText(/no offers/i));
        expect(screen.getByRole('heading', { name: /offers to manage/i })).toBeInTheDocument();
    });
});

// ── 4. Offer list rendering ───────────────────────────────────────────────────

describe('OfferManagePage — list rendering', () => {
    it('renders artwork title as a link', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer()]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        const link = screen.getByRole('link', { name: 'Черноморски залез' });
        expect(link).toHaveAttribute('href', '/artworks/chernomorski-zalez');
    });

    it('displays offered price in EUR', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ offered_price_cents: 150_000 })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        expect(screen.getByText(/€1,500\.00/)).toBeInTheDocument();
    });

    it('displays counter price when present', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ counter_price_cents: 180_000 })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        expect(screen.getByText(/€1,800\.00/)).toBeInTheDocument();
    });

    it('does not show counter price when null', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ counter_price_cents: null })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        expect(screen.queryByText(/counter:/i)).not.toBeInTheDocument();
    });
});

// ── 5. Status badges ──────────────────────────────────────────────────────────

describe('OfferManagePage — status badges', () => {
    it.each([
        ['submitted', 'Submitted'],
        ['countered', 'Countered'],
        ['accepted',  'Accepted'],
        ['rejected',  'Rejected'],
        ['paid',      'Paid'],
    ])('shows "%s" status label as "%s"', async (status, label) => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ status })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        expect(screen.getByText(label)).toBeInTheDocument();
    });
});

// ── 6. Respond toggle ─────────────────────────────────────────────────────────

describe('OfferManagePage — Respond toggle', () => {
    it('shows Respond button for submitted offers', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ status: 'submitted' })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        expect(screen.getByRole('button', { name: /respond/i })).toBeInTheDocument();
    });

    it('does not show Respond button for accepted offers', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ status: 'accepted' })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        expect(screen.queryByRole('button', { name: /respond/i })).not.toBeInTheDocument();
    });

    it('clicking Respond expands the CounterOfferForm', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer()]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));

        fireEvent.click(screen.getByRole('button', { name: /respond/i }));
        expect(screen.getByText(/respond to this offer/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /send counter/i })).toBeInTheDocument();
    });

    it('clicking Respond again (Close) collapses the form', async () => {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer()]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));

        fireEvent.click(screen.getByRole('button', { name: /respond/i }));
        fireEvent.click(screen.getByRole('button', { name: /close/i }));
        expect(screen.queryByText(/respond to this offer/i)).not.toBeInTheDocument();
    });
});

// ── 7. CounterOfferForm actions ───────────────────────────────────────────────

describe('OfferManagePage — CounterOfferForm', () => {
    async function openForm() {
        mockGet.mockResolvedValue(makePaginatedResponse([makeOffer({ id: 3 })]));
        render(<Wrapper><OfferManagePage /></Wrapper>);
        await waitFor(() => screen.getByText('Черноморски залез'));
        fireEvent.click(screen.getByRole('button', { name: /respond/i }));
    }

    it('Send counter calls POST /sell-now-offers/:id/counter with correct cents', async () => {
        await openForm();
        mockPost.mockResolvedValue({ data: {} });

        fireEvent.change(screen.getByPlaceholderText(/counter price/i), { target: { value: '1500' } });
        fireEvent.click(screen.getByRole('button', { name: /send counter/i }));

        await waitFor(() => expect(mockPost).toHaveBeenCalledWith(
            '/sell-now-offers/3/counter',
            expect.objectContaining({ counter_price_cents: 150_000, currency: 'EUR' }),
        ));
    });

    it('Send counter button disabled when price input is empty', async () => {
        await openForm();
        expect(screen.getByRole('button', { name: /send counter/i })).toBeDisabled();
    });

    it('Accept as-is calls POST /sell-now-offers/:id/accept', async () => {
        await openForm();
        mockPost.mockResolvedValue({ data: {} });

        fireEvent.click(screen.getByRole('button', { name: /accept as-is/i }));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/sell-now-offers/3/accept'));
    });

    it('Reject calls POST /sell-now-offers/:id/reject', async () => {
        await openForm();
        mockPost.mockResolvedValue({ data: {} });

        fireEvent.click(screen.getByRole('button', { name: /^reject$/i }));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/sell-now-offers/3/reject'));
    });

    it('shows error message when counter action fails', async () => {
        await openForm();
        mockPost.mockRejectedValue(new Error('Server error'));

        fireEvent.change(screen.getByPlaceholderText(/counter price/i), { target: { value: '1000' } });
        fireEvent.click(screen.getByRole('button', { name: /send counter/i }));

        await waitFor(() => expect(screen.getByText(/action failed/i)).toBeInTheDocument());
    });
});
