import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { echo } from '@/lib/echo';
import type { Auction, ArtLot } from '@/lib/api';

type BidEvent = {
    bidId: number;
    auctionItemId: number;
    amountCents: number;
    currency: string;
    nextBidCents: number;
};

type AuctionWithItems = Auction & { items?: ArtLot[] };

export default function AuctionRoomPage() {
    const { id } = useParams<{ id: string }>();
    const queryClient = useQueryClient();
    const [bidAmount, setBidAmount] = useState('');
    const [activeLotId, setActiveLotId] = useState<number | null>(null);
    const [liveBids, setLiveBids] = useState<Record<number, BidEvent>>({});

    const { data: auction, isLoading } = useQuery({
        queryKey: ['auction', id],
        queryFn: async () => {
            const res = await api.get<AuctionWithItems>(`/auctions/${id}`);
            return res.data;
        },
        enabled: !!id,
    });

    // Subscribe to real-time bid events via Reverb
    useEffect(() => {
        if (!id) return;
        const channel = echo.channel(`auction.${id}`);
        channel.listen('.bid.placed', (e: BidEvent) => {
            setLiveBids((prev) => ({ ...prev, [e.auctionItemId]: e }));
            void queryClient.invalidateQueries({ queryKey: ['auction', id] });
        });
        return () => {
            channel.stopListening('.bid.placed');
            echo.leave(`auction.${id}`);
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
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['auction', id] });
            setBidAmount('');
        },
    });

    if (isLoading) {
        return <div className="animate-pulse h-64 bg-gray-50 rounded-lg" />;
    }

    if (!auction) {
        return <p className="text-red-600">Auction not found.</p>;
    }

    return (
        <div>
            <nav className="text-sm text-gray-400 mb-6">
                <Link to="/auctions" className="hover:text-black">Auctions</Link>
                <span className="mx-2">/</span>
                <span className="text-gray-700">{auction.title}</span>
                {auction.status === 'live' && (
                    <span className="ml-3 inline-block rounded-full bg-red-100 text-red-700 px-2 py-0.5 text-xs font-medium animate-pulse">
                        LIVE
                    </span>
                )}
            </nav>

            <h1 className="text-3xl font-semibold mb-8">{auction.title}</h1>

            {(!auction.items || auction.items.length === 0) && (
                <p className="text-gray-500 text-sm">No lots in this auction yet.</p>
            )}

            <div className="grid md:grid-cols-2 gap-6">
                {(auction.items ?? []).map((lot) => {
                    const live = liveBids[lot.id];
                    const currentBidCents = live?.amountCents ?? lot.current_bid_cents;

                    const currentBid = currentBidCents
                        ? `${(currentBidCents / 100).toFixed(2)} ${lot.currency}`
                        : lot.starting_bid_cents
                        ? `Starting: ${(lot.starting_bid_cents / 100).toFixed(2)} ${lot.currency}`
                        : 'No bids yet';

                    return (
                        <div
                            key={lot.id}
                            className={`rounded-lg border px-5 py-5 transition-colors ${live ? 'border-black' : 'border-gray-200'}`}
                        >
                            <div className="flex items-start justify-between mb-3">
                                <div>
                                    <p className="text-xs text-gray-400">Lot #{lot.id}</p>
                                    <p className="font-semibold text-lg mt-0.5">{currentBid}</p>
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        {lot.bid_count} bid{lot.bid_count !== 1 ? 's' : ''}
                                        {live && <span className="ml-2 text-green-700 font-medium">● Live</span>}
                                    </p>
                                </div>
                                <span className="text-xs rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">
                                    {lot.status}
                                </span>
                            </div>

                            {auction.status === 'live' && lot.status === 'active' && (
                                <div className="flex gap-2 mt-3">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder={live ? `Min ${((live.nextBidCents) / 100).toFixed(2)}` : 'Your bid'}
                                        value={activeLotId === lot.id ? bidAmount : ''}
                                        onChange={(e) => {
                                            setActiveLotId(lot.id);
                                            setBidAmount(e.target.value);
                                        }}
                                        className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                                    />
                                    <button
                                        disabled={bidMutation.isPending || !bidAmount}
                                        onClick={() => {
                                            if (!bidAmount) return;
                                            bidMutation.mutate({
                                                lotId: lot.id,
                                                amount: parseFloat(bidAmount),
                                            });
                                        }}
                                        className="rounded-md bg-black text-white px-4 py-2 text-sm font-medium disabled:opacity-40 hover:bg-gray-800"
                                    >
                                        Bid
                                    </button>
                                </div>
                            )}

                            {bidMutation.isError && activeLotId === lot.id && (
                                <p className="text-xs text-red-600 mt-2">Bid failed. Please try again.</p>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
