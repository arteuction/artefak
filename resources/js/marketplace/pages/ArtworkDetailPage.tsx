import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import type { Artwork } from '@/lib/api';

export default function ArtworkDetailPage() {
    const { slug } = useParams<{ slug: string }>();
    const { user } = useAuth();
    const [showOfferModal, setShowOfferModal] = useState(false);
    const [offerPrice, setOfferPrice] = useState('');
    const [offerNote, setOfferNote] = useState('');

    const { data: artwork, isLoading, isError } = useQuery({
        queryKey: ['artwork', slug],
        queryFn: async () => {
            const res = await api.get<Artwork>(`/artworks/${slug}`);
            return res.data;
        },
        enabled: !!slug,
    });

    // Find the active lot for this artwork to submit a Sell Now offer
    const { data: lots } = useQuery({
        queryKey: ['artwork-lots', slug],
        queryFn: async () => {
            const res = await api.get<{ data: Array<{ id: number; status: string; gallery_id: number }> }>(
                `/artworks/${slug}/lots`,
            );
            return res.data.data;
        },
        enabled: !!slug && artwork?.status === 'listed',
    });

    const activeLot = lots?.find((l) => l.status === 'active');

    const offerMutation = useMutation({
        mutationFn: async () => {
            const res = await api.post('/sell-now-offers', {
                art_lot_id: activeLot?.id,
                offered_price_cents: Math.round(parseFloat(offerPrice) * 100),
                currency: 'BGN',
                notes: offerNote || undefined,
            });
            return res.data;
        },
        onSuccess: () => {
            setShowOfferModal(false);
            setOfferPrice('');
            setOfferNote('');
        },
    });

    if (isLoading) {
        return (
            <div className="animate-pulse space-y-4">
                <div className="h-8 bg-gray-100 rounded w-1/3" />
                <div className="aspect-video bg-gray-100 rounded-lg" />
            </div>
        );
    }

    if (isError || !artwork) {
        return <p className="text-red-600">Artwork not found.</p>;
    }

    const meta = [
        artwork.medium && `Medium: ${artwork.medium}`,
        artwork.dimensions && `Dimensions: ${artwork.dimensions}`,
        artwork.year_created && `Year: ${artwork.year_created}`,
        artwork.is_original ? 'Original' : artwork.edition_number != null
            ? `Edition ${artwork.edition_number}/${artwork.edition_total}`
            : null,
    ].filter(Boolean);

    const statusBadge: Record<string, string> = {
        listed: 'bg-green-100 text-green-800',
        in_auction: 'bg-blue-100 text-blue-800',
        sold: 'bg-gray-100 text-gray-600',
        draft: 'bg-yellow-100 text-yellow-800',
        archived: 'bg-gray-100 text-gray-400',
    };

    return (
        <div className="max-w-4xl">
            <nav className="text-sm text-gray-400 mb-6">
                <Link to="/artworks" className="hover:text-black">Artworks</Link>
                <span className="mx-2">/</span>
                <span className="text-gray-700">{artwork.title}</span>
            </nav>

            <div className="grid md:grid-cols-2 gap-10">
                <div className="aspect-square bg-gray-100 rounded-lg flex items-center justify-center text-gray-300 text-8xl">
                    🖼
                </div>

                <div>
                    <Link
                        to={`/artists/${artwork.artist.id}`}
                        className="text-sm text-gray-500 hover:text-black"
                    >
                        {artwork.artist.name}
                    </Link>
                    <h1 className="text-3xl font-semibold mt-1 mb-3">{artwork.title}</h1>

                    <span className={`inline-block rounded-full px-3 py-1 text-xs font-medium mb-4 ${statusBadge[artwork.status] ?? 'bg-gray-100 text-gray-600'}`}>
                        {artwork.status.replace('_', ' ')}
                    </span>

                    {meta.length > 0 && (
                        <ul className="text-sm text-gray-600 space-y-1 mb-4">
                            {meta.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    )}

                    {artwork.description && (
                        <p className="text-sm text-gray-700 leading-relaxed mb-6">{artwork.description}</p>
                    )}

                    {artwork.status === 'listed' && activeLot && user?.role === 'buyer' && (
                        <button
                            onClick={() => setShowOfferModal(true)}
                            className="rounded-md bg-black text-white px-6 py-2.5 text-sm font-medium hover:bg-gray-800"
                        >
                            Make an offer
                        </button>
                    )}

                    {artwork.status === 'listed' && !user && (
                        <p className="text-sm text-gray-500">
                            <Link to="/login" className="underline hover:no-underline">Sign in</Link> to make an offer.
                        </p>
                    )}
                </div>
            </div>

            {/* Offer modal */}
            {showOfferModal && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-xl p-8 max-w-sm w-full mx-4">
                        <h2 className="text-xl font-semibold mb-1">Make an offer</h2>
                        <p className="text-sm text-gray-500 mb-5">For: {artwork.title}</p>

                        <div className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">Offer price (BGN) *</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    required
                                    value={offerPrice}
                                    onChange={(e) => setOfferPrice(e.target.value)}
                                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium mb-1">Note (optional)</label>
                                <textarea
                                    rows={2}
                                    value={offerNote}
                                    onChange={(e) => setOfferNote(e.target.value)}
                                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                                />
                            </div>

                            {offerMutation.isError && (
                                <p className="text-xs text-red-600">Failed to submit offer.</p>
                            )}
                            {offerMutation.isSuccess && (
                                <p className="text-xs text-green-700">Offer submitted successfully!</p>
                            )}

                            <div className="flex gap-3">
                                <button
                                    onClick={() => setShowOfferModal(false)}
                                    className="flex-1 rounded-md border border-gray-300 py-2 text-sm hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    disabled={!offerPrice || offerMutation.isPending}
                                    onClick={() => offerMutation.mutate()}
                                    className="flex-1 rounded-md bg-black text-white py-2 text-sm font-medium disabled:opacity-40 hover:bg-gray-800"
                                >
                                    {offerMutation.isPending ? 'Submitting…' : 'Submit offer'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
