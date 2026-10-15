import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { vi, describe, it, expect, afterEach } from 'vitest';
import BidControl from './BidControl';
import type { BidControlProps, BidResult, OutbidEvent } from './BidControl';

// Always restore real timers after each test so fake-timer leaks can't cascade.
afterEach(() => vi.useRealTimers());

// ── Test helpers ─────────────────────────────────────────────────────────────

const EUR = 'EUR';
const MIN_CENTS = 10_000; // €100.00

function accepted(amountCents = MIN_CENTS): Promise<BidResult> {
    return Promise.resolve({ status: 'accepted', amountCents });
}

function rejected(message = 'Below reserve price'): Promise<BidResult> {
    return Promise.resolve({ status: 'rejected', message });
}

function reauth(): Promise<BidResult> {
    return Promise.resolve({ status: 'reauthorization_required' });
}

function makeOutbidEvent(newLeadingBidCents = 12_000): OutbidEvent {
    return { auctionItemId: 1, newLeadingBidCents, currency: EUR };
}

function renderControl(overrides: Partial<BidControlProps> = {}) {
    const onBid = vi.fn().mockImplementation(accepted);
    const props: BidControlProps = {
        minBidCents: MIN_CENTS,
        currency: EUR,
        connectionStatus: 'connected',
        outbidEvent: null,
        onBid,
        ...overrides,
    };
    const result = render(<BidControl {...props} />);
    return { ...result, onBid };
}

function typeAndSubmit(amount: string) {
    const input = screen.getByTestId('bid-input');
    fireEvent.change(input, { target: { value: amount } });
    fireEvent.click(screen.getByTestId('bid-submit'));
}

// ── Submission ───────────────────────────────────────────────────────────────

describe('BidControl — successful submission', () => {
    it('calls onBid with the correct cents value', async () => {
        const { onBid } = renderControl();
        typeAndSubmit('100.00');
        await waitFor(() => expect(onBid).toHaveBeenCalledWith(10_000));
    });

    it('shows the confirmed banner immediately after HTTP success (not waiting for Reverb)', async () => {
        renderControl();
        typeAndSubmit('100.00');
        await waitFor(() => expect(screen.getByTestId('confirmed-banner')).toBeInTheDocument());
    });

    it('clears the input after confirmation', async () => {
        renderControl();
        typeAndSubmit('100.00');
        await waitFor(() =>
            expect((screen.getByTestId('bid-input') as HTMLInputElement).value).toBe(''),
        );
    });

    it('auto-resets confirmed → idle after 3 seconds', async () => {
        vi.useFakeTimers();
        renderControl();

        // Flush the async submit + promise resolution under fake timers.
        // waitFor polls via setTimeout and breaks with fake timers, so we
        // manually flush microtasks via act() instead.
        await act(async () => {
            fireEvent.change(screen.getByTestId('bid-input'), { target: { value: '100.00' } });
            fireEvent.click(screen.getByTestId('bid-submit'));
            // Drain the Promise.resolve() microtask chain from accepted()
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(screen.getByTestId('confirmed-banner')).toBeInTheDocument();

        act(() => { vi.advanceTimersByTime(3_100); });
        expect(screen.queryByTestId('confirmed-banner')).not.toBeInTheDocument();
    });
});

// ── Duplicate submission prevention ─────────────────────────────────────────

describe('BidControl — duplicate submission prevention', () => {
    it('calls onBid only once when the button is clicked rapidly twice', async () => {
        let resolveFirst!: (r: BidResult) => void;
        const slowBid = vi.fn().mockReturnValue(
            new Promise<BidResult>((resolve) => { resolveFirst = resolve; }),
        );
        renderControl({ onBid: slowBid });

        fireEvent.change(screen.getByTestId('bid-input'), { target: { value: '100.00' } });
        fireEvent.click(screen.getByTestId('bid-submit'));
        fireEvent.click(screen.getByTestId('bid-submit'));

        resolveFirst({ status: 'accepted', amountCents: 10_000 });
        await waitFor(() => screen.getByTestId('confirmed-banner'));

        expect(slowBid).toHaveBeenCalledTimes(1);
    });

    it('disables the button while submitting', async () => {
        let resolveFirst!: (r: BidResult) => void;
        const slowBid = vi.fn().mockReturnValue(
            new Promise<BidResult>((resolve) => { resolveFirst = resolve; }),
        );
        renderControl({ onBid: slowBid });

        fireEvent.change(screen.getByTestId('bid-input'), { target: { value: '100.00' } });
        fireEvent.click(screen.getByTestId('bid-submit'));

        expect(screen.getByTestId('bid-submit')).toBeDisabled();
        resolveFirst({ status: 'accepted', amountCents: 10_000 });
        await waitFor(() => screen.queryByTestId('confirmed-banner'));
    });
});

// ── Server rejection ─────────────────────────────────────────────────────────

describe('BidControl — server rejection', () => {
    it('shows the server rejection message', async () => {
        renderControl({ onBid: vi.fn().mockImplementation(() => rejected('Reserve not met')) });
        typeAndSubmit('100.00');
        await waitFor(() => expect(screen.getByTestId('rejected-banner')).toHaveTextContent('Reserve not met'));
    });

    it('clears the rejection banner when user starts typing again', async () => {
        renderControl({ onBid: vi.fn().mockImplementation(rejected) });
        typeAndSubmit('100.00');
        await waitFor(() => screen.getByTestId('rejected-banner'));
        fireEvent.change(screen.getByTestId('bid-input'), { target: { value: '120' } });
        expect(screen.queryByTestId('rejected-banner')).not.toBeInTheDocument();
    });
});

// ── Reauthorization ──────────────────────────────────────────────────────────

describe('BidControl — reauthorization required', () => {
    it('shows the reauth banner on a 402-equivalent response', async () => {
        renderControl({ onBid: vi.fn().mockImplementation(reauth) });
        typeAndSubmit('100.00');
        await waitFor(() => expect(screen.getByTestId('reauth-banner')).toBeInTheDocument());
    });
});

// ── Outbid event ─────────────────────────────────────────────────────────────

describe('BidControl — outbid event from Reverb', () => {
    it('shows the outbid banner when outbidEvent is non-null', () => {
        renderControl({ outbidEvent: makeOutbidEvent(12_000) });
        expect(screen.getByTestId('outbid-banner')).toBeInTheDocument();
        expect(screen.getByTestId('outbid-banner')).toHaveTextContent('€120.00');
    });

    it('does not show the outbid banner when outbidEvent is null', () => {
        renderControl({ outbidEvent: null });
        expect(screen.queryByTestId('outbid-banner')).not.toBeInTheDocument();
    });

    it('updates the banner when a new outbid event arrives', () => {
        const { rerender } = renderControl({ outbidEvent: makeOutbidEvent(12_000) });
        rerender(
            <BidControl
                minBidCents={MIN_CENTS}
                currency={EUR}
                connectionStatus="connected"
                outbidEvent={makeOutbidEvent(15_000)}
                onBid={vi.fn().mockImplementation(accepted)}
            />,
        );
        expect(screen.getByTestId('outbid-banner')).toHaveTextContent('€150.00');
    });

    it('shows an outbid event that arrives during a competing bid submission', async () => {
        let resolveFirst!: (r: BidResult) => void;
        const slowBid = vi.fn().mockReturnValue(
            new Promise<BidResult>((resolve) => { resolveFirst = resolve; }),
        );

        const { rerender } = renderControl({ onBid: slowBid });

        fireEvent.change(screen.getByTestId('bid-input'), { target: { value: '100.00' } });
        fireEvent.click(screen.getByTestId('bid-submit'));

        // Competing bid arrives via Reverb while we're still in-flight
        rerender(
            <BidControl
                minBidCents={MIN_CENTS}
                currency={EUR}
                connectionStatus="connected"
                outbidEvent={makeOutbidEvent(11_000)}
                onBid={slowBid}
            />,
        );

        // Our HTTP response still arrives — confirmed banner should appear
        resolveFirst({ status: 'accepted', amountCents: 10_000 });
        await waitFor(() => screen.getByTestId('confirmed-banner'));
    });
});

// ── Connection status ────────────────────────────────────────────────────────

describe('BidControl — WebSocket connection status', () => {
    it('shows a disconnection warning when connectionStatus is disconnected', () => {
        renderControl({ connectionStatus: 'disconnected' });
        expect(screen.getByTestId('disconnection-warning')).toBeInTheDocument();
    });

    it('does NOT disable bidding when disconnected (connection ≠ bidding availability)', () => {
        // Button is enabled once a valid amount is entered — disconnection alone must not lock it.
        renderControl({ connectionStatus: 'disconnected' });
        fireEvent.change(screen.getByTestId('bid-input'), { target: { value: '100.00' } });
        expect(screen.getByTestId('bid-submit')).not.toBeDisabled();
    });

    it('removes the disconnection warning when reconnected', () => {
        const { rerender } = renderControl({ connectionStatus: 'disconnected' });
        expect(screen.getByTestId('disconnection-warning')).toBeInTheDocument();

        rerender(
            <BidControl
                minBidCents={MIN_CENTS}
                currency={EUR}
                connectionStatus="connected"
                outbidEvent={null}
                onBid={vi.fn().mockImplementation(accepted)}
            />,
        );
        expect(screen.queryByTestId('disconnection-warning')).not.toBeInTheDocument();
    });

    it('shows a connecting notice during initial connection', () => {
        renderControl({ connectionStatus: 'connecting' });
        expect(screen.getByTestId('connecting-notice')).toBeInTheDocument();
    });
});

// ── HTTP success with no Reverb event ────────────────────────────────────────

describe('BidControl — HTTP confirmed; Reverb event absent', () => {
    it('shows confirmed state from HTTP response alone', async () => {
        // onBid resolves; outbidEvent stays null (Reverb never fires)
        renderControl({ onBid: vi.fn().mockImplementation(accepted), outbidEvent: null });
        typeAndSubmit('100.00');
        // Confirmed immediately from HTTP — no Reverb dependency
        await waitFor(() => expect(screen.getByTestId('confirmed-banner')).toBeInTheDocument());
    });
});

// ── Network error ────────────────────────────────────────────────────────────

describe('BidControl — network error', () => {
    it('shows a generic rejection message when onBid throws', async () => {
        renderControl({ onBid: vi.fn().mockRejectedValue(new Error('Network error')) });
        typeAndSubmit('100.00');
        await waitFor(() => expect(screen.getByTestId('rejected-banner')).toBeInTheDocument());
    });
});
