import { useState, useMemo } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import type { Artwork } from '@/lib/api';
import { Badge, Button, Input, Spinner } from '@/components/ui';
import { useJsonLd } from '@/lib/useJsonLd';
import { useDocTitle } from '@/lib/useDocTitle';

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    listed:     'default',
    in_auction: 'auction',
    sold:       'sold',
    draft:      'draft',
    archived:   'draft',
};

const STATUS_LABEL: Record<string, string> = {
    listed:     'For Sale',
    in_auction: 'In Auction',
    sold:       'Sold',
    draft:      'Draft',
    archived:   'Archived',
};

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

    const { data: lots } = useQuery({
        queryKey: ['artwork-lots', slug],
        queryFn: async () => {
            const res = await api.get<{ data: Array<{ id: number; status: string }> }>(
                `/artworks/${slug}/lots`,
            );
            return res.data.data;
        },
        enabled: !!slug && artwork?.status === 'listed',
    });

    const activeLot = lots?.find((l) => l.status === 'active');

    const offerMutation = useMutation({
        mutationFn: async () => {
            const res = await api.post(`/art-lots/${activeLot!.id}/sell-now-offers`, {
                offered_price_cents: Math.round(parseFloat(offerPrice) * 100),
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

    const jsonLd = useMemo(() => {
        if (!artwork) return null;
        return {
            '@context': 'https://schema.org',
            '@type': 'VisualArtwork',
            name: artwork.title,
            creator: { '@type': 'Person', name: artwork.artist.name },
            ...(artwork.medium ? { artMedium: artwork.medium } : {}),
            ...(artwork.year_created ? { dateCreated: String(artwork.year_created) } : {}),
            ...(artwork.description ? { description: artwork.description } : {}),
        };
    }, [artwork]);
    useJsonLd(jsonLd);
    useDocTitle(artwork?.title ?? null);

    if (isLoading) {
        return (
            <div className="flex justify-center py-24">
                <Spinner size="lg" />
            </div>
        );
    }

    if (isError || !artwork) {
        return <p className="text-red-600">Artwork not found.</p>;
    }

    const metaRows = [
        artwork.medium && { label: 'Medium', value: artwork.medium },
        artwork.dimensions && { label: 'Dimensions', value: artwork.dimensions },
        artwork.year_created && { label: 'Year', value: String(artwork.year_created) },
        {
            label: 'Edition',
            value: artwork.is_original
                ? 'Original'
                : artwork.edition_number != null
                ? `${artwork.edition_number} / ${artwork.edition_total}`
                : null,
        },
    ].filter((r): r is { label: string; value: string } => !!r && r.value != null);

    return (
        <div className="max-w-4xl">
            <nav className="text-sm text-[var(--color-text-faint)] mb-6 flex items-center gap-1.5">
                <Link to="/artworks" className="hover:text-[var(--color-text)] transition-colors">
                    Artworks
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">{artwork.title}</span>
            </nav>

            <div className="grid md:grid-cols-2 gap-10">
                {/* Image panel */}
                <div className="aspect-artwork bg-[var(--color-bg-subtle)] rounded-[var(--radius-lg)] flex items-center justify-center overflow-hidden">
                    <svg className="h-20 w-20 text-[var(--color-border-strong)]" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>

                {/* Info panel */}
                <div>
                    <Link
                        to={`/artists/${artwork.artist.id}`}
                        className="text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                    >
                        {artwork.artist.name}
                    </Link>
                    <h1 className="text-3xl font-semibold mt-1 mb-3">{artwork.title}</h1>

                    <div className="mb-4">
                        <Badge variant={STATUS_VARIANT[artwork.status] ?? 'default'}>
                            {STATUS_LABEL[artwork.status] ?? artwork.status}
                        </Badge>
                    </div>

                    {metaRows.length > 0 && (
                        <dl className="text-sm space-y-1 mb-5">
                            {metaRows.map(({ label, value }) => (
                                <div key={label} className="flex gap-2">
                                    <dt className="text-[var(--color-text-muted)] w-24 shrink-0">{label}</dt>
                                    <dd className="text-[var(--color-text)]">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    )}

                    {artwork.description && (
                        <p className="text-sm text-[var(--color-text-muted)] leading-relaxed mb-6">
                            {artwork.description}
                        </p>
                    )}

                    {artwork.status === 'listed' && activeLot && user?.role === 'buyer' && (
                        <Button onClick={() => setShowOfferModal(true)} size="lg">
                            Make an offer
                        </Button>
                    )}

                    {artwork.status === 'listed' && !user && (
                        <p className="text-sm text-[var(--color-text-muted)]">
                            <Link to="/login" className="underline hover:no-underline">Sign in</Link>{' '}
                            to make an offer.
                        </p>
                    )}

                    {artwork.status === 'sold' && (
                        <p className="text-sm text-[var(--color-sold-text)]">This artwork has been sold.</p>
                    )}
                </div>
            </div>

            {/* Offer modal */}
            {showOfferModal && (
                <div
                    className="fixed inset-0 bg-black/60 flex items-center justify-center z-50"
                    onClick={(e) => { if (e.target === e.currentTarget) setShowOfferModal(false); }}
                    role="presentation"
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="offer-modal-title"
                        className="bg-[var(--color-bg)] rounded-[var(--radius-lg)] p-8 max-w-sm w-full mx-4 shadow-xl"
                    >
                        <h2 id="offer-modal-title" className="text-xl font-semibold mb-1">Make an offer</h2>
                        <p className="text-sm text-[var(--color-text-muted)] mb-5">
                            {artwork.title}
                        </p>

                        <div className="space-y-4">
                            <Input
                                label="Offer price (EUR)"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                                value={offerPrice}
                                onChange={(e) => setOfferPrice(e.target.value)}
                            />
                            <div>
                                <label className="block text-sm font-medium text-[var(--color-text)] mb-1">
                                    Note <span className="text-[var(--color-text-faint)] font-normal">(optional)</span>
                                </label>
                                <textarea
                                    rows={2}
                                    aria-label="Note for the offer"
                                    value={offerNote}
                                    onChange={(e) => setOfferNote(e.target.value)}
                                    className="w-full rounded-[var(--radius-sm)] border border-[var(--color-border)] px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                                />
                            </div>

                            {offerMutation.isError && (
                                <p className="text-xs text-red-600">Failed to submit offer. Please try again.</p>
                            )}
                            {offerMutation.isSuccess && (
                                <p className="text-xs text-green-700">Offer submitted successfully!</p>
                            )}

                            <div className="flex gap-3">
                                <Button
                                    variant="secondary"
                                    className="flex-1"
                                    onClick={() => setShowOfferModal(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    className="flex-1"
                                    disabled={!offerPrice}
                                    loading={offerMutation.isPending}
                                    onClick={() => offerMutation.mutate()}
                                >
                                    Submit offer
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
