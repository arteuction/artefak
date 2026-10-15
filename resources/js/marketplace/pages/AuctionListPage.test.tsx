/**
 * Integration tests for AuctionListPage.
 *
 * Tests cover: loading, error, empty state, list rendering with status badges,
 * live auction "Join Live" link, pagination controls, and page-change behavior.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import AuctionListPage from './AuctionListPage';

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

function makeAuction(id: number, status: string, overrides: Record<string, unknown> = {}) {
    return {
        id,
        title: `Test Auction ${id}`,
        slug: `test-auction-${id}`,
        status,
        currency: 'EUR',
        starts_at: '2026-11-01T10:00:00Z',
        ends_at: '2026-11-03T20:00:00Z',
        ...overrides,
    };
}

function makePaginatedResponse(
    items: ReturnType<typeof makeAuction>[],
    page = 1,
    lastPage = 1,
) {
    return {
        data: items,
        meta: { current_page: page, last_page: lastPage, per_page: 15, total: items.length },
        links: {},
    };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
});

// ── 1. Loading and error states ───────────────────────────────────────────────

describe('AuctionListPage — loading and error', () => {
    it('shows a spinner while loading', async () => {
        mockGet.mockReturnValue(new Promise(() => {}));
        render(<Wrapper><AuctionListPage /></Wrapper>);
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('shows an error message on API failure', async () => {
        mockGet.mockRejectedValue(new Error('Network error'));
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/failed to load auctions/i)).toBeInTheDocument());
    });
});

// ── 2. Empty state ────────────────────────────────────────────────────────────

describe('AuctionListPage — empty state', () => {
    it('shows empty state when no auctions exist', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText(/no auctions scheduled/i)).toBeInTheDocument());
    });
});

// ── 3. Auction list rendering ─────────────────────────────────────────────────

describe('AuctionListPage — list rendering', () => {
    it('renders the page heading', async () => {
        mockGet.mockResolvedValue({ data: makePaginatedResponse([]) });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Auctions' })).toBeInTheDocument());
    });

    it('renders auction titles', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled'), makeAuction(2, 'closed')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Test Auction 1')).toBeInTheDocument());
        expect(screen.getByText('Test Auction 2')).toBeInTheDocument();
    });

    it('shows "Upcoming" badge for scheduled auction', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Upcoming')).toBeInTheDocument());
    });

    it('shows "Live" badge for live auction', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'live')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Live')).toBeInTheDocument());
    });

    it('shows "Closed" badge for closed auction', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'closed')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => expect(screen.getByText('Closed')).toBeInTheDocument());
    });

    it('renders date range for auctions with start and end dates', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        // Date range text is visible (exact format may vary by locale)
        const rows = screen.getAllByText(/202[0-9]/);
        expect(rows.length).toBeGreaterThanOrEqual(1);
    });
});

// ── 4. Live auction — "Join Live" link ────────────────────────────────────────

describe('AuctionListPage — Join Live link', () => {
    it('shows "Join Live" link for live auction pointing to /auctions/:id/live', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(42, 'live')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 42'));

        const joinLink = screen.getByRole('link', { name: /join live/i });
        expect(joinLink).toHaveAttribute('href', '/auctions/42/live');
    });

    it('does not show "Join Live" for scheduled auction', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        expect(screen.queryByRole('link', { name: /join live/i })).not.toBeInTheDocument();
    });

    it('does not show "Join Live" for closed auction', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'closed')]),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        expect(screen.queryByRole('link', { name: /join live/i })).not.toBeInTheDocument();
    });
});

// ── 5. Pagination ─────────────────────────────────────────────────────────────

describe('AuctionListPage — pagination', () => {
    it('hides pagination when there is only one page', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')], 1, 1),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        expect(screen.queryByRole('button', { name: /previous/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /next/i })).not.toBeInTheDocument();
    });

    it('shows pagination controls when there are multiple pages', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')], 1, 3),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        expect(screen.getByRole('button', { name: /previous/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /next/i })).toBeInTheDocument();
    });

    it('Previous button is disabled on the first page', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')], 1, 3),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        expect(screen.getByRole('button', { name: /previous/i })).toBeDisabled();
    });

    it('Next button is disabled on the last page', async () => {
        mockGet.mockResolvedValue({
            data: makePaginatedResponse([makeAuction(1, 'scheduled')], 3, 3),
        });
        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));
        expect(screen.getByRole('button', { name: /next/i })).toBeDisabled();
    });

    it('clicking Next fetches the next page', async () => {
        mockGet
            .mockResolvedValueOnce({
                data: makePaginatedResponse([makeAuction(1, 'scheduled')], 1, 2),
            })
            .mockResolvedValueOnce({
                data: makePaginatedResponse([makeAuction(2, 'closed')], 2, 2),
            });

        render(<Wrapper><AuctionListPage /></Wrapper>);
        await waitFor(() => screen.getByText('Test Auction 1'));

        fireEvent.click(screen.getByRole('button', { name: /next/i }));

        await waitFor(() => expect(screen.getByText('Test Auction 2')).toBeInTheDocument());
        // Page counter should reflect current page
        expect(screen.getByText(/2\s*\/\s*2/)).toBeInTheDocument();
    });
});
