import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, useRef } from 'react';
import { api } from '@/lib/api';
import { echo } from '@/lib/echo';
import type { Auction, ArtLot } from '@/lib/api';
import { Badge, Button, Input, Spinner } from '@/components/ui';
import { useDocTitle } from '@/lib/useDocTitle';

type BidEvent = {
    bidId: number;
    auctionItemId: number;
    amountCents: number;
    currency: string;
    nextBidCents: number;
    bidderId?: number;
};

type AuctionWithItems = Auction & { items?: ArtLot[] };

type ConnectionStatus = 'connecting' | 'connected' | 'disconnected';

function formatEur(cents: number, currency = 'EUR'): string {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(cents / 100);
}

function useCountdown(endsAt: string | null): string {
    const [remaining, setRemaining] = useState('');

    useEffect(() => {
        if (!endsAt) { setRemaining(''); return; }

        const tick = () => {
            const diff = new Date(endsAt).getTime() - Date.now();
            if (diff <= 0) { setRemaining('Ended'); return; }
            const h = Math.floor(diff / 3_600_000);
            const m = Math.floor((diff % 3_600_000) / 60_000);
            const s = Math.floor((diff % 60_000) / 1_000);
            setRemaining(h > 0 ? `${h}h ${m}m ${s}s` : `${m}m ${s}s`);
        };

        tick();
        const id = setInterval(tick, 1_000);
        return () => clearInterval(id);
    }, [endsAt]);

    return remaining;
}

export default function AuctionRoomPage() {
    const { id } = useParams<{ id: string }>();
    const queryClient = useQueryClient();
    const [bidAmounts, setBidAmounts] = useState<Record<number, string>>({});
    const [liveBids, setLiveBids] = useState<Record<number, BidEvent>>({});
    const [recentBids, setRecentBids] = useState<BidEvent[]>([]);
    const [outbid, setOutbid] = useState<number | null>(null);
    const [connStatus, setConnStatus] = useState<ConnectionStatus>('connecting');
    const channelRef = useRef<ReturnType<typeof echo.channel> | null>(null);

    const { data: auction, isLoading } = useQuery({
        queryKey: ['auction', id],
        queryFn: async () => {
            const res = await api.get<AuctionWithItems>(`/auctions/${id}`);
            return res.data;
        },
        enabled: !!id,
    });

    const countdown = useCountdown(auction?.ends_at ?? null);
    useDocTitle(auction?.title ?? null);

    // Subscribe to real-time bid events via Reverb
    useEffect(() => {
        if (!id) return;

        const channel = echo.channel(`auction.${id}`);
        channelRef.current = channel;
        setConnStatus('connecting');

        channel
            .subscribed(() => setConnStatus('connected'))
            .error(() => setConnStatus('disconnected'))
            .listen('.bid.placed', (e: BidEvent) => {
                setLiveBids((prev) => ({ ...prev, [e.auctionItemId]: e }));
                setRecentBids((prev) => [e, ...prev].slice(0, 20));
                // Show outbid alert for 4 seconds
                setOutbid(e.auctionItemId);
                setTimeout(() => setOutbid(null), 4_000);
                void queryClient.invalidateQueries({ queryKey: ['auction', id] });
            });

        return () => {
            channel.stopListening('.bid.placed');
            echo.leave(`auction.${id}`);
            channelRef.current = null;
        };
    }, [id, queryClient]);

    const bidMutation = useMutation({
        mutationFn: async ({ lotId, amount }: { lotId: number; amount: number }) => {
            const res = await api.post('/bids', {
                auction_item_id: lotId,
                amount_cents: Math.round(amount * 100),
            });
            return res.data;
        },
        onSuccess: (_data, { lotId }) => {
            setBidAmounts((prev) => ({ ...prev, [lotId]: '' }));
            void queryClient.invalidateQueries({ queryKey: ['auction', id] });
        },
    });

    if (isLoading) {
        return (
            <div className="flex justify-center py-24">
                <Spinner size="lg" />
            </div>
        );
    }

    if (!auction) {
        return <p className="text-red-600">Auction not found.</p>;
    }

    const isLive = auction.status === 'live';

    return (
        <div className="max-w-4xl">
            {/* Header */}
            <nav className="text-sm text-[var(--color-text-faint)] mb-4 flex items-center gap-1.5">
                <Link to="/auctions" className="hover:text-[var(--color-text)] transition-colors">
                    Auctions
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">{auction.title}</span>
            </nav>

            <div className="flex items-center gap-3 mb-6 flex-wrap">
                <h1 className="text-3xl font-semibold flex-1">{auction.title}</h1>

                {isLive && (
                    <Badge variant="live" pulse>
                        LIVE
                    </Badge>
                )}

                {/* Countdown */}
                {isLive && countdown && (
                    <span className="text-sm font-mono text-[var(--color-text-muted)] bg-[var(--color-bg-muted)] px-3 py-1 rounded-[var(--radius-sm)]">
                        {countdown}
                    </span>
                )}

                {/* Connection indicator */}
                <span
                    className={[
                        'flex items-center gap-1.5 text-xs px-2 py-1 rounded-[var(--radius-full)]',
                        connStatus === 'connected'
                            ? 'bg-green-50 text-green-700'
                            : connStatus === 'disconnected'
                            ? 'bg-red-50 text-red-700'
                            : 'bg-[var(--color-bg-muted)] text-[var(--color-text-faint)]',
                    ].join(' ')}
                >
                    <span
                        className={[
                            'h-1.5 w-1.5 rounded-full',
                            connStatus === 'connected'
                                ? 'bg-green-500'
                                : connStatus === 'disconnected'
                                ? 'bg-red-500 animate-pulse'
                                : 'bg-gray-400 animate-pulse',
                        ].join(' ')}
                    />
                    {connStatus === 'connected' ? 'Connected' : connStatus === 'disconnected' ? 'Reconnecting…' : 'Connecting…'}
                </span>
            </div>

            {(!auction.items || auction.items.length === 0) && (
                <p className="text-[var(--color-text-muted)] text-sm">No lots in this auction yet.</p>
            )}

            <div className="grid md:grid-cols-3 gap-6">
                {/* Lots */}
                <div className="md:col-span-2 space-y-4">
                    {(auction.items ?? []).map((lot) => {
                        const live = liveBids[lot.id];
                        const currentBidCents = live?.amountCents ?? lot.current_bid_cents;
                        const minNextCents = live?.nextBidCents
                            ?? (currentBidCents ? currentBidCents + 1 : lot.starting_bid_cents ?? 0);

                        const isOutbid = outbid === lot.id;

                        return (
                            <div
                                key={lot.id}
                                className={[
                                    'rounded-[var(--radius-lg)] border px-5 py-5 transition-all',
                                    isOutbid ? 'border-amber-400 bg-amber-50' : live ? 'border-[var(--color-border-strong)]' : 'border-[var(--color-border)]',
                                ].join(' ')}
                            >
                                <div className="flex items-start justify-between mb-3">
                                    <div>
                                        <p className="text-xs text-[var(--color-text-faint)]">Lot #{lot.id}</p>
                                        <p className="font-semibold text-xl mt-0.5">
                                            {currentBidCents
                                                ? formatEur(currentBidCents, lot.currency)
                                                : lot.starting_bid_cents
                                                ? `Starting ${formatEur(lot.starting_bid_cents, lot.currency)}`
                                                : 'No bids yet'}
                                        </p>
                                        <p className="text-xs text-[var(--color-text-muted)] mt-0.5">
                                            {lot.bid_count} bid{lot.bid_count !== 1 ? 's' : ''}
                                            {live && (
                                                <span className="ml-2 text-green-700 font-medium">● Just updated</span>
                                            )}
                                        </p>
                                    </div>
                                    <Badge variant={lot.status === 'active' ? 'default' : 'draft'}>
                                        {lot.status}
                                    </Badge>
                                </div>

                                {isOutbid && (
                                    <div className="mb-3 text-sm text-amber-800 bg-amber-100 rounded-[var(--radius-sm)] px-3 py-2">
                                        New bid placed — minimum next bid is{' '}
                                        <span className="font-medium">
                                            {formatEur(minNextCents, lot.currency)}
                                        </span>
                                    </div>
                                )}

                                {isLive && lot.status === 'active' && (
                                    <div className="flex gap-2 mt-3">
                                        <Input
                                            type="number"
                                            min={(minNextCents / 100).toFixed(2)}
                                            step="0.01"
                                            placeholder={`Min ${formatEur(minNextCents, lot.currency)}`}
                                            value={bidAmounts[lot.id] ?? ''}
                                            onChange={(e) => setBidAmounts((prev) => ({ ...prev, [lot.id]: e.target.value }))}
                                            className="flex-1"
                                        />
                                        <Button
                                            disabled={bidMutation.isPending || !bidAmounts[lot.id]}
                                            loading={bidMutation.isPending && bidMutation.variables?.lotId === lot.id}
                                            onClick={() => {
                                                const amt = bidAmounts[lot.id];
                                                if (!amt) return;
                                                bidMutation.mutate({ lotId: lot.id, amount: parseFloat(amt) });
                                            }}
                                        >
                                            Bid
                                        </Button>
                                    </div>
                                )}

                                {bidMutation.isError && bidMutation.variables?.lotId === lot.id && (
                                    <p className="text-xs text-red-600 mt-2">
                                        Bid failed. Please check the amount and try again.
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Bid history sidebar */}
                <div className="space-y-2">
                    <h2 className="text-sm font-medium text-[var(--color-text)] mb-3">Recent bids</h2>
                    {recentBids.length === 0 && (
                        <p className="text-xs text-[var(--color-text-faint)]">
                            {isLive ? 'No bids yet — be the first!' : 'No live bid stream available.'}
                        </p>
                    )}
                    {recentBids.map((bid, i) => (
                        <div
                            key={`${bid.bidId}-${i}`}
                            className="flex justify-between items-center text-xs border-b border-[var(--color-border)] pb-2"
                        >
                            <span className="text-[var(--color-text-muted)]">Lot #{bid.auctionItemId}</span>
                            <span className="font-medium text-[var(--color-text)]">
                                {formatEur(bid.amountCents, bid.currency)}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
