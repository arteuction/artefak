import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import type { Artwork, PaginatedResponse } from '@/lib/api';
import { Badge, EmptyState, Spinner } from '@/components/ui';

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    draft:      'draft',
    listed:     'default',
    in_auction: 'auction',
    sold:       'sold',
    archived:   'draft',
};

const STATUS_LABEL: Record<string, string> = {
    draft:      'Draft',
    listed:     'Listed',
    in_auction: 'In Auction',
    sold:       'Sold',
    archived:   'Archived',
};

export default function DashboardPage() {
    const { user } = useAuth();

    const { data, isLoading } = useQuery({
        queryKey: ['my-artworks'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Artwork>>('/artworks/mine');
            return res.data;
        },
        enabled: user?.role === 'artist',
    });

    if (!user) {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)] mb-4">
                    You need to sign in to access your dashboard.
                </p>
                <Link
                    to="/login"
                    className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                >
                    Sign in
                </Link>
            </div>
        );
    }

    if (user.role !== 'artist') {
        return <p className="text-[var(--color-text-muted)]">Dashboard is available for artists only.</p>;
    }

    const counts = data
        ? Object.entries(
              data.data.reduce<Record<string, number>>((acc, a) => {
                  acc[a.status] = (acc[a.status] ?? 0) + 1;
                  return acc;
              }, {}),
          )
        : [];

    return (
        <div>
            <div className="flex items-center justify-between mb-6 gap-4 flex-wrap">
                <div>
                    <h1 className="text-3xl font-semibold">My artworks</h1>
                    {counts.length > 0 && (
                        <div className="flex items-center gap-2 mt-2 flex-wrap">
                            {counts.map(([status, count]) => (
                                <span key={status} className="inline-flex items-center gap-1 text-xs text-[var(--color-text-muted)]">
                                    <Badge variant={STATUS_VARIANT[status] ?? 'default'}>
                                        {STATUS_LABEL[status] ?? status}
                                    </Badge>
                                    <span>{count}</span>
                                </span>
                            ))}
                        </div>
                    )}
                </div>
                <Link
                    to="/dashboard/artworks/new"
                    className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                >
                    + Add artwork
                </Link>
            </div>

            {isLoading && (
                <div className="flex justify-center py-12">
                    <Spinner size="lg" />
                </div>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No artworks yet"
                    description="Add your first artwork to get started."
                    action={
                        <Link
                            to="/dashboard/artworks/new"
                            className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                        >
                            Add artwork
                        </Link>
                    }
                />
            )}

            {data && data.data.length > 0 && (
                <div className="space-y-2">
                    {data.data.map((artwork) => (
                        <div
                            key={artwork.id}
                            className="flex items-center justify-between rounded-[var(--radius-lg)] border border-[var(--color-border)] px-4 py-3 bg-[var(--color-bg)] hover:border-[var(--color-border-strong)] transition-colors"
                        >
                            <div className="min-w-0">
                                <p className="font-medium truncate">{artwork.title}</p>
                                <p className="text-xs text-[var(--color-text-muted)] mt-0.5">
                                    {artwork.medium ?? 'No medium'}{artwork.year_created ? ` · ${artwork.year_created}` : ''}
                                </p>
                            </div>
                            <div className="flex items-center gap-3 shrink-0 ml-4">
                                <Badge variant={STATUS_VARIANT[artwork.status] ?? 'default'}>
                                    {STATUS_LABEL[artwork.status] ?? artwork.status}
                                </Badge>
                                {artwork.status === 'draft' && (
                                    <Link
                                        to={`/dashboard/artworks/${artwork.slug}/edit`}
                                        className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                                    >
                                        Edit
                                    </Link>
                                )}
                                <Link
                                    to={`/artworks/${artwork.slug}`}
                                    className="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                                >
                                    View
                                </Link>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
