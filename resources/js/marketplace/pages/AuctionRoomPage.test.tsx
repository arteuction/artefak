/**
 * Integration tests for AuctionRoomPage.
 *
 * HTTP is mocked via vi.mock('@/lib/api'). Realtime (Reverb/Echo) is mocked via
 * a deterministic fake channel adapter — vi.mock('@/lib/echo'). This lets tests
 * fire events at will without a WebSocket server.
 *
 * The fake channel records listeners and lets tests call emit(eventName, payload)
 * to push events synchronously into the component under test.
 */

import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/context/AuthContext';
import type { AuthUser } from '@/lib/auth';
import AuctionRoomPage from './AuctionRoomPage';

// ── Fake Echo channel ─────────────────────────────────────────────────────────

type Listener = (payload: unknown) => void;

class FakeChannel {
    private listeners: Record<string, Listener[]> = {};
    private _subscribed: (() => void) | null = null;
    private _error: (() => void) | null = null;

    subscribed(cb: () => void): this { this._subscribed = cb; return this; }
    error(cb: () => void): this { this._error = cb; return this; }

    listen(event: string, cb: Listener): this {
        (this.listeners[event] ??= []).push(cb);
        return this;
    }
    stopListening(event: string): this {
        delete this.listeners[event];
        return this;
    }

    /** Test helper: fire a subscribed callback */
    triggerSubscribed() { this._subscribed?.(); }
    /** Test helper: fire an error callback */
    triggerError() { this._error?.(); }
    /** Test helper: emit an event to all registered listeners */
    emit(event: string, payload: unknown) {
        for (const cb of this.listeners[event] ?? []) cb(payload);
    }
}

// Maps channel names → FakeChannel instances; tests access these to emit events.
const channels: Record<string, FakeChannel> = {};
const privateChannels: Record<string, FakeChannel> = {};

vi.mock('@/lib/echo', () => ({
    echo: {
        channel(name: string) {
            channels[name] = new FakeChannel();
            return channels[name];
        },
        private(name: string) {
            privateChannels[name] = new FakeChannel();
            return privateChannels[name];
        },
        leave(name: string) {
            delete channels[name];
            delete privateChannels[name];
        },
    },
}));

// ── Fake API ──────────────────────────────────────────────────────────────────

// vi.mock factories are hoisted, so mock fns must be created with vi.hoisted.
const { mockGet, mockPost } = vi.hoisted(() => ({
    mockGet: vi.fn(),
    mockPost: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const mod = await importOriginal<typeof import('@/lib/api')>();
    return {
        ...mod,
        api: { get: mockGet, post: mockPost },
    };
});

// Silence React Query retries in tests.
function makeQueryClient() {
    return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

// ── Auth context helper ───────────────────────────────────────────────────────

const BUYER: AuthUser = { id: 42, name: 'Test Buyer', email: 'buyer@test.com', role: 'buyer' };

function Wrapper({ user = BUYER, children }: { user?: AuthUser | null; children: React.ReactNode }) {
    return (
        <QueryClientProvider client={makeQueryClient()}>
            <AuthContext.Provider value={{ user, loading: false, refresh: async () => {} }}>
                <MemoryRouter initialEntries={['/auctions/7']}>
                    <Routes>
                        <Route path="/auctions/:id" element={children} />
                    </Routes>
                </MemoryRouter>
            </AuthContext.Provider>
        </QueryClientProvider>
    );
}

// ── Auction fixture ───────────────────────────────────────────────────────────

const ENDS_AT = new Date(Date.now() + 10 * 60 * 1_000).toISOString(); // 10 min from now

const LOT_1 = {
    id: 101,
    artwork_id: 1,
    auction_id: 7,
    status: 'active',
    reserve_price_cents: null,
    starting_bid_cents: 5_000,
    current_bid_cents: null,
    bid_count: 0,
    currency: 'EUR',
};

const LOT_2 = {
    id: 102,
    artwork_id: 2,
    auction_id: 7,
    status: 'active',
    reserve_price_cents: null,
    starting_bid_cents: 8_000,
    current_bid_cents: 10_000,
    bid_count: 1,
    currency: 'EUR',
};

function makeAuction(overrides: Record<string, unknown> = {}) {
    return {
        id: 7,
        title: 'Spring Auction 2026',
        slug: 'spring-2026',
        status: 'live',
        starts_at: null,
        ends_at: ENDS_AT,
        created_at: '2026-01-01T00:00:00Z',
        items: [LOT_1, LOT_2],
        ...overrides,
    };
}

// Resolve auction GET then flush channels (subscribed callback sets connStatus).
async function renderAndConnect(user: AuthUser | null = BUYER) {
    mockGet.mockResolvedValue({ data: makeAuction() });
    render(<Wrapper user={user}><AuctionRoomPage /></Wrapper>);

    // Wait for data to load — use heading role to avoid matching breadcrumb text too
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Spring Auction 2026' })).toBeInTheDocument());

    // Trigger subscribed on public channel
    act(() => channels['auction.7']?.triggerSubscribed());
    if (user) act(() => privateChannels[`bidder.${user.id}`]?.triggerSubscribed());

    return { publicChannel: channels['auction.7']!, privateChannel: user ? privateChannels[`bidder.${user.id}`] : undefined };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
    vi.clearAllMocks();
    Object.keys(channels).forEach(k => delete channels[k]);
    Object.keys(privateChannels).forEach(k => delete privateChannels[k]);
});

afterEach(() => {
    vi.useRealTimers();
});

// ── 1. Initial load ───────────────────────────────────────────────────────────

describe('AuctionRoomPage — initial load', () => {
    it('renders the auction title and LIVE badge', async () => {
        await renderAndConnect();
        expect(screen.getByRole('heading', { name: 'Spring Auction 2026' })).toBeInTheDocument();
        expect(screen.getByText('LIVE')).toBeInTheDocument();
    });

    it('renders both lots', async () => {
        await renderAndConnect();
        expect(screen.getByText('Lot #101')).toBeInTheDocument();
        expect(screen.getByText('Lot #102')).toBeInTheDocument();
    });

    it('shows starting bid on a lot with no current bid', async () => {
        await renderAndConnect();
        expect(screen.getByText(/Starting.*€50/)).toBeInTheDocument();
    });

    it('shows current bid on a lot that has bids', async () => {
        await renderAndConnect();
        expect(screen.getByText('€100.00')).toBeInTheDocument();
    });

    it('renders the countdown timer for a live auction with ends_at', async () => {
        await renderAndConnect();
        expect(screen.getByTestId('auction-timer')).toBeInTheDocument();
    });

    it('shows "Connected" after the channel fires subscribed', async () => {
        await renderAndConnect();
        expect(screen.getByText('Connected')).toBeInTheDocument();
    });
});

// ── 2. Bid submission — HTTP authoritative ────────────────────────────────────

describe('AuctionRoomPage — bid submission', () => {
    it('shows confirmed banner immediately from HTTP, no Reverb needed', async () => {
        mockPost.mockResolvedValue({ data: { id: 500, amount_cents: 6_000 } });
        await renderAndConnect();

        const inputs = screen.getAllByTestId('bid-input');
        fireEvent.change(inputs[0], { target: { value: '60.00' } });
        fireEvent.click(screen.getAllByTestId('bid-submit')[0]);

        await waitFor(() => expect(screen.getByTestId('confirmed-banner')).toBeInTheDocument());
        expect(mockPost).toHaveBeenCalledWith('/auctions/7/items/101/bids', { amount_cents: 6_000 });
    });

    it('maps HTTP 402 to reauthorization_required state in BidControl', async () => {
        const err = Object.assign(new Error('Payment required'), { response: { status: 402 } });
        mockPost.mockRejectedValue(err);
        await renderAndConnect();

        const inputs = screen.getAllByTestId('bid-input');
        fireEvent.change(inputs[0], { target: { value: '60.00' } });
        fireEvent.click(screen.getAllByTestId('bid-submit')[0]);

        await waitFor(() => expect(screen.getByTestId('reauth-banner')).toBeInTheDocument());
    });

    it('maps other HTTP errors to rejection message in BidControl', async () => {
        const err = Object.assign(new Error('Bid failed'), {
            response: { status: 422, data: { message: 'Below reserve price' } },
        });
        mockPost.mockRejectedValue(err);
        await renderAndConnect();

        const inputs = screen.getAllByTestId('bid-input');
        fireEvent.change(inputs[0], { target: { value: '60.00' } });
        fireEvent.click(screen.getAllByTestId('bid-submit')[0]);

        await waitFor(() => expect(screen.getByTestId('rejected-banner')).toBeInTheDocument());
        expect(screen.getByTestId('rejected-banner').textContent).toContain('Below reserve price');
    });
});

// ── 3. Realtime events ────────────────────────────────────────────────────────

describe('AuctionRoomPage — realtime bid.placed event', () => {
    it('updates recent bids sidebar when a bid is placed', async () => {
        const { publicChannel } = await renderAndConnect();

        act(() => publicChannel.emit('.bid.placed', {
            bidId: 999,
            auctionItemId: 101,
            amountCents: 7_000,
            currency: 'EUR',
            nextBidCents: 7_500,
            bidderId: 99,
        }));

        // €70.00 appears at least once (sidebar + possibly lot price)
        await waitFor(() => expect(screen.getAllByText('€70.00').length).toBeGreaterThanOrEqual(1));
    });

    it('marks the updated lot with "Just updated" indicator', async () => {
        const { publicChannel } = await renderAndConnect();

        act(() => publicChannel.emit('.bid.placed', {
            bidId: 1001,
            auctionItemId: 102,
            amountCents: 11_000,
            currency: 'EUR',
            nextBidCents: 11_500,
            bidderId: 55,
        }));

        await waitFor(() => expect(screen.getByText('● Just updated')).toBeInTheDocument());
    });
});

describe('AuctionRoomPage — realtime auction-item.status-changed event', () => {
    it('reflects lot closure when status-changed event arrives', async () => {
        const { publicChannel } = await renderAndConnect();

        act(() => publicChannel.emit('.auction-item.status-changed', {
            auctionItemId: 101,
            newStatus: 'sold',
            hammerPriceCents: 6_500,
            currency: 'EUR',
        }));

        await waitFor(() => expect(screen.getByText('sold')).toBeInTheDocument());
    });

    it('hides BidControl for a sold lot', async () => {
        const { publicChannel } = await renderAndConnect();
        const controlsBefore = screen.getAllByTestId('bid-submit');

        act(() => publicChannel.emit('.auction-item.status-changed', {
            auctionItemId: 101,
            newStatus: 'sold',
            hammerPriceCents: 6_500,
            currency: 'EUR',
        }));

        await waitFor(() => {
            expect(screen.getAllByTestId('bid-submit').length).toBeLessThan(controlsBefore.length);
        });
    });
});

describe('AuctionRoomPage — outbid notification (private channel)', () => {
    it('shows the outbid banner on the correct lot only', async () => {
        const { privateChannel } = await renderAndConnect();

        act(() => privateChannel!.emit('.bid.outbid', {
            auctionItemId: 102,
            previousBidCents: 10_000,
            newLeadingBidCents: 11_000,
            currency: 'EUR',
        }));

        await waitFor(() => expect(screen.getByTestId('outbid-banner')).toBeInTheDocument());
        // Only one outbid banner (not on lot 101)
        expect(screen.getAllByTestId('outbid-banner')).toHaveLength(1);
    });

    it('does not subscribe to private channel when unauthenticated', async () => {
        mockGet.mockResolvedValue({ data: makeAuction() });
        render(<Wrapper user={null}><AuctionRoomPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Spring Auction 2026' }));

        // No private channel created
        expect(Object.keys(privateChannels)).toHaveLength(0);
    });
});

// ── 4. Race conditions ────────────────────────────────────────────────────────

describe('AuctionRoomPage — race conditions', () => {
    it('shows confirmed from HTTP even when a competing Reverb event arrives mid-flight', async () => {
        let resolvePost!: (v: unknown) => void;
        mockPost.mockReturnValue(new Promise(r => { resolvePost = r; }));

        const { publicChannel } = await renderAndConnect();

        const inputs = screen.getAllByTestId('bid-input');
        fireEvent.change(inputs[0], { target: { value: '60.00' } });
        fireEvent.click(screen.getAllByTestId('bid-submit')[0]);

        // Competing bid fires while ours is in-flight
        act(() => publicChannel.emit('.bid.placed', {
            bidId: 888,
            auctionItemId: 101,
            amountCents: 7_000,
            currency: 'EUR',
            nextBidCents: 7_500,
        }));

        // Our HTTP resolves after the event
        act(() => resolvePost({ data: { id: 501, amount_cents: 6_000 } }));

        await waitFor(() => expect(screen.getByTestId('confirmed-banner')).toBeInTheDocument());
    });

    it('HTTP confirmed with no Reverb event ever firing', async () => {
        mockPost.mockResolvedValue({ data: { id: 502, amount_cents: 6_000 } });
        await renderAndConnect();

        const inputs = screen.getAllByTestId('bid-input');
        fireEvent.change(inputs[0], { target: { value: '60.00' } });
        fireEvent.click(screen.getAllByTestId('bid-submit')[0]);

        // No Reverb event fired — confirmed must appear from HTTP alone
        await waitFor(() => expect(screen.getByTestId('confirmed-banner')).toBeInTheDocument());
    });
});

// ── 5. WebSocket connection lifecycle ─────────────────────────────────────────

describe('AuctionRoomPage — WebSocket lifecycle', () => {
    it('shows "Connecting…" initially', async () => {
        mockGet.mockResolvedValue({ data: makeAuction() });
        render(<Wrapper><AuctionRoomPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Spring Auction 2026' }));
        // Before subscribed fires, status is connecting
        expect(screen.getByText('Connecting…')).toBeInTheDocument();
    });

    it('shows "Reconnecting…" after channel error', async () => {
        const { publicChannel } = await renderAndConnect();

        // triggerError sets connStatus → disconnected synchronously via setState
        await act(async () => { publicChannel.triggerError(); });

        expect(screen.getByText('Reconnecting…')).toBeInTheDocument();
    });

    it('shows disconnection warning in BidControl when channel errors', async () => {
        const { publicChannel } = await renderAndConnect();

        await act(async () => { publicChannel.triggerError(); });

        // Both lots have a BidControl — both will show the warning
        expect(screen.getAllByTestId('disconnection-warning').length).toBeGreaterThanOrEqual(1);
    });

    it('does not disable bid submit when disconnected (HTTP still available)', async () => {
        const { publicChannel } = await renderAndConnect();

        await act(async () => { publicChannel.triggerError(); });

        expect(screen.getAllByTestId('disconnection-warning').length).toBeGreaterThanOrEqual(1);

        const inputs = screen.getAllByTestId('bid-input');
        fireEvent.change(inputs[0], { target: { value: '60.00' } });
        expect(screen.getAllByTestId('bid-submit')[0]).not.toBeDisabled();
    });
});

// ── 6. Cleanup on unmount ─────────────────────────────────────────────────────

describe('AuctionRoomPage — cleanup', () => {
    it('leaves the public channel on unmount', async () => {
        const { unmount } = render(<Wrapper><AuctionRoomPage /></Wrapper>);
        mockGet.mockResolvedValue({ data: makeAuction() });
        await waitFor(() => screen.queryByText('Spring Auction 2026'));
        unmount();
        // Channel should have been removed by echo.leave
        expect(channels['auction.7']).toBeUndefined();
    });

    it('leaves the private channel on unmount', async () => {
        const { unmount } = render(<Wrapper><AuctionRoomPage /></Wrapper>);
        mockGet.mockResolvedValue({ data: makeAuction() });
        await waitFor(() => screen.queryByText('Spring Auction 2026'));
        unmount();
        expect(privateChannels[`bidder.${BUYER.id}`]).toBeUndefined();
    });
});

// ── 7. Non-live auction ───────────────────────────────────────────────────────

describe('AuctionRoomPage — non-live auction', () => {
    it('hides BidControl when auction is not live', async () => {
        mockGet.mockResolvedValue({ data: makeAuction({ status: 'scheduled' }) });
        render(<Wrapper><AuctionRoomPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Spring Auction 2026' }));
        expect(screen.queryByTestId('bid-submit')).not.toBeInTheDocument();
    });

    it('hides the LIVE badge when auction is not live', async () => {
        mockGet.mockResolvedValue({ data: makeAuction({ status: 'scheduled' }) });
        render(<Wrapper><AuctionRoomPage /></Wrapper>);
        await waitFor(() => screen.getByRole('heading', { name: 'Spring Auction 2026' }));
        expect(screen.queryByText('LIVE')).not.toBeInTheDocument();
    });
});
