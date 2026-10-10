import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaginatedResponse, SellNowOffer } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { Badge, Button, EmptyState, Input, Spinner } from '@/components/ui';

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    pending:   'auction',
    countered: 'default',
    accepted:  'sold',
    rejected:  'draft',
    expired:   'draft',
    paid:      'sold',
};

const STATUS_LABEL: Record<string, string> = {
    pending:   'Pending',
    countered: 'Countered',
    accepted:  'Accepted',
    rejected:  'Rejected',
    expired:   'Expired',
    paid:      'Paid',
};

function formatEur(cents: number, currency = 'EUR'): string {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(cents / 100);
}

function CounterOfferForm({
    offer,
    onDone,
}: {
    offer: SellNowOffer;
    onDone: () => void;
}) {
    const [price, setPrice] = useState('');

    const counterMutation = useMutation({
        mutationFn: async () =>
            api.post(`/sell-now-offers/${offer.id}/counter`, {
                counter_price_cents: Math.round(parseFloat(price) * 100),
                currency: offer.currency,
            }),
        onSuccess: onDone,
    });

    const acceptMutation = useMutation({
        mutationFn: async () => api.post(`/sell-now-offers/${offer.id}/accept`),
        onSuccess: onDone,
    });

    const rejectMutation = useMutation({
        mutationFn: async () => api.post(`/sell-now-offers/${offer.id}/reject`),
        onSuccess: onDone,
    });

    const anyPending = counterMutation.isPending || acceptMutation.isPending || rejectMutation.isPending;

    return (
        <div className="mt-3 rounded-[var(--radius-md)] bg-[var(--color-bg-subtle)] border border-[var(--color-border)] p-4 space-y-3">
            <p className="text-sm font-medium">Respond to this offer</p>

            <div className="flex gap-2">
                <Input
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder={`Counter price (EUR)`}
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                    className="flex-1"
                />
                <Button
                    size="sm"
                    disabled={!price || anyPending}
                    loading={counterMutation.isPending}
                    onClick={() => counterMutation.mutate()}
                >
                    Send counter
                </Button>
            </div>

            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    disabled={anyPending}
                    loading={acceptMutation.isPending}
                    onClick={() => acceptMutation.mutate()}
                >
                    Accept as-is
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={anyPending}
                    loading={rejectMutation.isPending}
                    onClick={() => rejectMutation.mutate()}
                >
                    Reject
                </Button>
            </div>

            {(counterMutation.isError || acceptMutation.isError || rejectMutation.isError) && (
                <p className="text-xs text-red-600">Action failed. Please try again.</p>
            )}
        </div>
    );
}

export default function OfferManagePage() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [expandedId, setExpandedId] = useState<number | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['gallery-offers'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<SellNowOffer>>('/my/gallery-offers');
            return res.data;
        },
        enabled: user?.role === 'admin',
    });

    const refresh = () => void queryClient.invalidateQueries({ queryKey: ['gallery-offers'] });

    if (!user || user.role !== 'admin') {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)]">
                    Gallery workspace requires gallery staff access.
                </p>
            </div>
        );
    }

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">Offers to manage</h1>

            {isLoading && (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No offers"
                    description="Buyer offers on your consigned artworks will appear here."
                />
            )}

            {data && data.data.length > 0 && (
                <div className="space-y-3">
                    {data.data.map((offer) => (
                        <div
                            key={offer.id}
                            className="rounded-[var(--radius-lg)] border border-[var(--color-border)] px-5 py-4 bg-[var(--color-bg)]"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    {offer.artwork && (
                                        <Link
                                            to={`/artworks/${offer.artwork.slug}`}
                                            className="font-medium hover:underline block truncate"
                                        >
                                            {offer.artwork.title}
                                        </Link>
                                    )}
                                    <div className="flex items-center gap-3 mt-1 text-sm text-[var(--color-text-muted)]">
                                        <span>
                                            Offer:{' '}
                                            <span className="font-medium text-[var(--color-text)]">
                                                {formatEur(offer.offered_price_cents, offer.currency)}
                                            </span>
                                        </span>
                                        {offer.counter_price_cents && (
                                            <span>
                                                Counter:{' '}
                                                <span className="font-medium text-amber-700">
                                                    {formatEur(offer.counter_price_cents, offer.currency)}
                                                </span>
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    <Badge variant={STATUS_VARIANT[offer.status] ?? 'default'}>
                                        {STATUS_LABEL[offer.status] ?? offer.status}
                                    </Badge>
                                    {offer.status === 'pending' && (
                                        <button
                                            onClick={() => setExpandedId(expandedId === offer.id ? null : offer.id)}
                                            className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                                        >
                                            {expandedId === offer.id ? 'Close' : 'Respond'}
                                        </button>
                                    )}
                                </div>
                            </div>

                            {expandedId === offer.id && offer.status === 'pending' && (
                                <CounterOfferForm
                                    offer={offer}
                                    onDone={() => { setExpandedId(null); refresh(); }}
                                />
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
