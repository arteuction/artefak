import { useEffect, useRef, useState } from 'react';

// ── Public types ─────────────────────────────────────────────────────────────

export type BidResult =
    | { status: 'accepted'; amountCents: number }
    | { status: 'rejected'; message: string }
    | { status: 'reauthorization_required' };

export type OutbidEvent = {
    auctionItemId: number;
    newLeadingBidCents: number;
    currency: string;
};

export type ConnectionStatus = 'connecting' | 'connected' | 'disconnected';

// ── Internal state machine ───────────────────────────────────────────────────

type BidState =
    | { kind: 'idle' }
    | { kind: 'submitting' }
    | { kind: 'confirmed'; amountCents: number }
    | { kind: 'rejected'; message: string }
    | { kind: 'reauthorization_required' };

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatCurrency(cents: number, currency: string): string {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(cents / 100);
}

// ── Props ────────────────────────────────────────────────────────────────────

export type BidControlProps = {
    /** Minimum acceptable bid in cents. */
    minBidCents: number;
    currency: string;
    connectionStatus: ConnectionStatus;
    /**
     * Non-null when Reverb delivered an outbid event for this lot.
     * BidControl reflects it immediately without HTTP round-trip.
     * The parent should pass the latest event object; identity change drives the update.
     */
    outbidEvent: OutbidEvent | null;
    /**
     * Injected bid action — keeps HTTP concerns out of the component and
     * makes the submission path fully deterministic in tests.
     */
    onBid: (amountCents: number) => Promise<BidResult>;
    /** When true the component renders but bidding is not possible (e.g. lot closed). */
    disabled?: boolean;
};

// ── Component ────────────────────────────────────────────────────────────────

export default function BidControl({
    minBidCents,
    currency,
    connectionStatus,
    outbidEvent,
    onBid,
    disabled = false,
}: BidControlProps) {
    const [bidState, setBidState] = useState<BidState>({ kind: 'idle' });
    const [inputValue, setInputValue] = useState('');

    // Hard guard: prevents duplicate submissions regardless of React render timing.
    const submittingRef = useRef(false);

    // ── Reverb outbid event ─────────────────────────────────────────────────
    // Reflect a competing bid immediately. The identity of outbidEvent (not its
    // content) triggers the effect — the parent must pass a new object reference
    // for each distinct event.
    const outbidEventRef = useRef<OutbidEvent | null>(null);
    useEffect(() => {
        if (!outbidEvent) return;
        if (outbidEvent === outbidEventRef.current) return; // same reference, skip
        outbidEventRef.current = outbidEvent;

        // Show outbid state even if currently submitting — the competing bid landed.
        // The user's in-flight request may still succeed on the backend; that will
        // be reflected when the HTTP response arrives and setBidState is called.
        setBidState({ kind: 'idle' }); // reset to idle so outbid banner is rendered below
    }, [outbidEvent]);

    // ── Auto-reset confirmed → idle ─────────────────────────────────────────
    useEffect(() => {
        if (bidState.kind !== 'confirmed') return;
        const id = setTimeout(() => setBidState({ kind: 'idle' }), 3_000);
        return () => clearTimeout(id);
    }, [bidState]);

    // ── Submit handler ──────────────────────────────────────────────────────
    const handleSubmit = async () => {
        if (submittingRef.current || disabled) return;

        const rawCents = Math.round(parseFloat(inputValue) * 100);
        if (isNaN(rawCents) || rawCents < minBidCents) {
            setBidState({ kind: 'rejected', message: `Minimum bid is ${formatCurrency(minBidCents, currency)}` });
            return;
        }

        submittingRef.current = true;
        setBidState({ kind: 'submitting' });

        try {
            // HTTP response is authoritative. Confirmed = server accepted the bid.
            // We do NOT wait for a Reverb event to reflect success.
            const result = await onBid(rawCents);

            if (result.status === 'accepted') {
                setInputValue('');
                setBidState({ kind: 'confirmed', amountCents: result.amountCents });
            } else if (result.status === 'reauthorization_required') {
                setBidState({ kind: 'reauthorization_required' });
            } else {
                setBidState({ kind: 'rejected', message: result.message });
            }
        } catch (err: unknown) {
            const msg =
                (err as { response?: { data?: { message?: string } } })?.response?.data?.message
                ?? 'Something went wrong. Please try again.';
            setBidState({ kind: 'rejected', message: msg });
        } finally {
            submittingRef.current = false;
        }
    };

    // ── Derived flags ───────────────────────────────────────────────────────
    const isSubmitting = bidState.kind === 'submitting';
    const isDisabled   = disabled || isSubmitting;
    const minEur       = formatCurrency(minBidCents, currency);

    return (
        <div data-testid="bid-control">
            {/* Disconnection warning — shown but does NOT prevent bidding by default.
                The server validates every bid; the UI exposes the connection state
                and leaves the product decision (allow/block) to the caller via `disabled`. */}
            {connectionStatus === 'disconnected' && (
                <div
                    role="alert"
                    data-testid="disconnection-warning"
                    style={{
                        background: 'var(--color-bg-muted)',
                        border: '1px solid var(--color-border-strong)',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 0.875rem',
                        marginBottom: '0.75rem',
                        fontSize: '0.8125rem',
                        color: 'var(--color-text-muted)',
                        fontFamily: 'var(--font-ui)',
                    }}
                >
                    Live updates paused — bids will still be validated by the server.
                </div>
            )}

            {connectionStatus === 'connecting' && (
                <div
                    data-testid="connecting-notice"
                    style={{
                        fontSize: '0.75rem',
                        color: 'var(--color-text-faint)',
                        marginBottom: '0.5rem',
                        fontFamily: 'var(--font-ui)',
                    }}
                >
                    Connecting to live auction…
                </div>
            )}

            {/* Outbid banner */}
            {outbidEvent && bidState.kind === 'idle' && (
                <div
                    role="status"
                    data-testid="outbid-banner"
                    style={{
                        background: '#fffbeb',
                        border: '1px solid #d97706',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 0.875rem',
                        marginBottom: '0.75rem',
                        fontSize: '0.8125rem',
                        fontFamily: 'var(--font-ui)',
                        color: '#92400e',
                    }}
                >
                    You've been outbid. New leading bid:{' '}
                    <strong>{formatCurrency(outbidEvent.newLeadingBidCents, outbidEvent.currency)}</strong>
                </div>
            )}

            {/* Confirmed banner */}
            {bidState.kind === 'confirmed' && (
                <div
                    role="status"
                    data-testid="confirmed-banner"
                    style={{
                        background: '#f0fdf4',
                        border: '1px solid #22c55e',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 0.875rem',
                        marginBottom: '0.75rem',
                        fontSize: '0.8125rem',
                        fontFamily: 'var(--font-ui)',
                        color: '#166534',
                    }}
                >
                    Bid confirmed — {formatCurrency(bidState.amountCents, currency)}
                </div>
            )}

            {/* Rejected banner */}
            {bidState.kind === 'rejected' && (
                <div
                    role="alert"
                    data-testid="rejected-banner"
                    style={{
                        background: 'var(--color-live-bg)',
                        border: '1px solid var(--color-live)',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 0.875rem',
                        marginBottom: '0.75rem',
                        fontSize: '0.8125rem',
                        fontFamily: 'var(--font-ui)',
                        color: 'var(--color-live)',
                    }}
                >
                    {bidState.message}
                </div>
            )}

            {/* Reauthorization required */}
            {bidState.kind === 'reauthorization_required' && (
                <div
                    role="alert"
                    data-testid="reauth-banner"
                    style={{
                        background: '#fefce8',
                        border: '1px solid #ca8a04',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 0.875rem',
                        marginBottom: '0.75rem',
                        fontSize: '0.8125rem',
                        fontFamily: 'var(--font-ui)',
                        color: '#713f12',
                    }}
                >
                    Payment authorisation required. Please update your payment method to continue bidding.
                </div>
            )}

            {/* Bid input row */}
            {!disabled && (
                <div style={{ display: 'flex', gap: '0.5rem' }}>
                    <input
                        type="number"
                        data-testid="bid-input"
                        aria-label={`Bid amount in ${currency}, minimum ${minEur}`}
                        min={(minBidCents / 100).toFixed(2)}
                        step="0.01"
                        placeholder={`Min ${minEur}`}
                        value={inputValue}
                        disabled={isDisabled}
                        onChange={(e) => {
                            setInputValue(e.target.value);
                            // Clear rejection when user starts typing a new value
                            if (bidState.kind === 'rejected') setBidState({ kind: 'idle' });
                        }}
                        style={{
                            flex: 1,
                            padding: '0.5rem 0.75rem',
                            border: '1px solid var(--color-border)',
                            borderRadius: 'var(--radius-md)',
                            fontFamily: 'var(--font-ui)',
                            fontSize: '0.875rem',
                            color: 'var(--color-text)',
                            background: isDisabled ? 'var(--color-bg-muted)' : 'var(--color-bg)',
                        }}
                    />
                    <button
                        type="button"
                        data-testid="bid-submit"
                        aria-label="Submit bid"
                        disabled={isDisabled || !inputValue}
                        onClick={handleSubmit}
                        style={{
                            padding: '0.5rem 1.25rem',
                            background: isDisabled ? 'var(--color-bg-muted)' : 'var(--color-action)',
                            color: isDisabled ? 'var(--color-text-faint)' : 'var(--color-action-fg)',
                            border: 'none',
                            borderRadius: 'var(--radius-md)',
                            fontFamily: 'var(--font-ui)',
                            fontSize: '0.875rem',
                            fontWeight: 500,
                            cursor: isDisabled ? 'not-allowed' : 'pointer',
                        }}
                    >
                        {isSubmitting ? '…' : 'Bid'}
                    </button>
                </div>
            )}
        </div>
    );
}
