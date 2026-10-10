import { Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaginatedResponse, SellNowOffer } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { Badge, Button, EmptyState, Spinner } from '@/components/ui';

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    pending:   'default',
    countered: 'auction',
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

function CounterOfferRow({ offer, onAction }: { offer: SellNowOffer; onAction: () => void }) {
    const acceptMutation = useMutation({
        mutationFn: async () => api.post(`/sell-now-offers/${offer.id}/accept`),
        onSuccess: onAction,
    });

    const rejectMutation = useMutation({
        mutationFn: async () => api.post(`/sell-now-offers/${offer.id}/reject`),
        onSuccess: onAction,
    });

    return (
        <div className="mt-3 rounded-[var(--radius-md)] bg-[var(--color-bg-subtle)] border border-[var(--color-border)] p-4">
            <p className="text-sm font-medium text-[var(--color-text)]">
                Counter-offer received:{' '}
                <span className="text-amber-700">
                    {formatEur(offer.counter_price_cents!, offer.currency)}
                </span>
            </p>
            <p className="text-xs text-[var(--color-text-muted)] mt-1 mb-3">
                The gallery has proposed a new price. Accept or decline below.
            </p>
            <div className="flex gap-2">
                <Button
                    size="sm"
                    loading={acceptMutation.isPending}
                    disabled={rejectMutation.isPending}
                    onClick={() => acceptMutation.mutate()}
                >
                    Accept counter-offer
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    loading={rejectMutation.isPending}
                    disabled={acceptMutation.isPending}
                    onClick={() => rejectMutation.mutate()}
                >
                    Decline
                </Button>
            </div>
            {(acceptMutation.isError || rejectMutation.isError) && (
                <p className="text-xs text-red-600 mt-2">Action failed. Please try again.</p>
            )}
        </div>
    );
}

export default function OfferListPage() {
    const { user } = useAuth();
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['my-offers'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<SellNowOffer>>('/sell-now-offers');
            return res.data;
        },
        enabled: !!user,
    });

    const refreshOffers = () => {
        void queryClient.invalidateQueries({ queryKey: ['my-offers'] });
    };

    if (!user) {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)] mb-4">Sign in to view your offers.</p>
                <Link
                    to="/login"
                    className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                >
                    Sign in
                </Link>
            </div>
        );
    }

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">My offers</h1>

            {isLoading && (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No offers yet"
                    description="Browse artworks and make an offer to get started."
                    action={
                        <Link
                            to="/artworks"
                            className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                        >
                            Browse artworks
                        </Link>
                    }
                />
            )}

            {data && data.data.length > 0 && (
                <div className="space-y-4">
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
                                            className="font-medium hover:underline truncate block"
                                        >
                                            {offer.artwork.title}
                                        </Link>
                                    )}
                                    <div className="flex items-center gap-2 mt-1 text-sm text-[var(--color-text-muted)]">
                                        <span>
                                            Your offer:{' '}
                                            <span className="font-medium text-[var(--color-text)]">
                                                {formatEur(offer.offered_price_cents, offer.currency)}
                                            </span>
                                        </span>
                                        {offer.expires_at && offer.status === 'pending' && (
                                            <span className="text-xs text-[var(--color-text-faint)]">
                                                · expires{' '}
                                                {new Date(offer.expires_at).toLocaleDateString('en-GB', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                })}
                                            </span>
                                        )}
                                    </div>

                                    {offer.status === 'paid' && (
                                        <p className="text-xs text-[var(--color-sold-text)] mt-1 font-medium">
                                            Payment complete
                                        </p>
                                    )}
                                    {offer.status === 'accepted' && (
                                        <p className="text-xs text-[var(--color-text-muted)] mt-1">
                                            Accepted — awaiting payment confirmation.
                                        </p>
                                    )}
                                </div>
                                <Badge variant={STATUS_VARIANT[offer.status] ?? 'default'}>
                                    {STATUS_LABEL[offer.status] ?? offer.status}
                                </Badge>
                            </div>

                            {offer.status === 'countered' && offer.counter_price_cents != null && (
                                <CounterOfferRow offer={offer} onAction={refreshOffers} />
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
