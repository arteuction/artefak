import { useMemo, useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import type { Auction, PaginatedResponse } from '@/lib/api';
import { Badge, Button, EmptyState, Spinner } from '@/components/ui';
import { useJsonLd } from '@/lib/useJsonLd';

const AUCTION_STATUS_VARIANT: Record<string, 'live' | 'auction' | 'draft'> = {
    live:      'live',
    scheduled: 'auction',
    draft:     'draft',
    closed:    'draft',
    cancelled: 'draft',
};

const AUCTION_STATUS_LABEL: Record<string, string> = {
    live:      'Live',
    scheduled: 'Upcoming',
    draft:     'Draft',
    closed:    'Closed',
    cancelled: 'Cancelled',
};

function formatDateRange(start: string | null, end: string | null): string {
    const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' };
    const fmt = (d: string) => new Date(d).toLocaleDateString('en-GB', opts);
    if (!start) return '';
    return end ? `${fmt(start)} – ${fmt(end)}` : fmt(start);
}

export default function AuctionListPage() {
    const [page, setPage] = useState(1);

    const { data, isLoading, isFetching, isError } = useQuery({
        queryKey: ['auctions', page],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Auction>>('/auctions', { params: { page } });
            return res.data;
        },
        placeholderData: keepPreviousData,
    });

    const liveAuction = data?.data.find((a) => a.status === 'live') ?? null;
    const jsonLd = useMemo(() => {
        if (!liveAuction) return null;
        return {
            '@context': 'https://schema.org',
            '@type': 'Event',
            name: liveAuction.title,
            eventStatus: 'https://schema.org/EventScheduled',
            ...(liveAuction.starts_at ? { startDate: liveAuction.starts_at } : {}),
            ...(liveAuction.ends_at ? { endDate: liveAuction.ends_at } : {}),
        };
    }, [liveAuction]);
    useJsonLd(jsonLd);

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">Auctions</h1>

            {isLoading && (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {isError && (
                <p className="text-red-600 text-sm">Failed to load auctions.</p>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No auctions scheduled"
                    description="Check back soon for upcoming live auctions."
                />
            )}

            {data && data.data.length > 0 && (
                <div className={isFetching ? 'opacity-60 transition-opacity' : ''}>
                <div className="space-y-3">
                    {data.data.map((auction) => (
                        <div
                            key={auction.id}
                            className="flex items-center justify-between rounded-[var(--radius-lg)] border border-[var(--color-border)] px-5 py-4 hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
                        >
                            <div>
                                <h2 className="font-medium text-[var(--color-text)]">{auction.title}</h2>
                                {(auction.starts_at ?? auction.ends_at) && (
                                    <p className="text-xs text-[var(--color-text-muted)] mt-0.5">
                                        {formatDateRange(auction.starts_at, auction.ends_at)}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Badge
                                    variant={AUCTION_STATUS_VARIANT[auction.status] ?? 'default'}
                                    pulse={auction.status === 'live'}
                                >
                                    {AUCTION_STATUS_LABEL[auction.status] ?? auction.status}
                                </Badge>
                                {auction.status === 'live' && (
                                    <Link
                                        to={`/auctions/${auction.id}/live`}
                                        className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                                    >
                                        Join Live
                                    </Link>
                                )}
                            </div>
                        </div>
                    ))}
                </div>

                {data.meta.last_page > 1 && (
                    <div className="flex justify-center items-center gap-3 mt-6">
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={page === 1 || isFetching}
                        >
                            Previous
                        </Button>
                        <span className="text-sm text-[var(--color-text-muted)]">
                            {page} / {data.meta.last_page}
                        </span>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setPage((p) => Math.min(data.meta.last_page, p + 1))}
                            disabled={page === data.meta.last_page || isFetching}
                        >
                            Next
                        </Button>
                    </div>
                )}
                </div>
            )}
        </div>
    );
}
