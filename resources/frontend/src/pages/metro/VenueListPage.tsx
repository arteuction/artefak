import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaginatedResponse, Venue } from '@/lib/api';
import { Badge, EmptyState, Spinner } from '@/components/ui';

export default function VenueListPage() {
    const { data, isLoading } = useQuery({
        queryKey: ['venues'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Venue>>('/venues');
            return res.data;
        },
    });

    return (
        <div>
            <div className="mb-6">
                <h1 className="text-3xl font-semibold">ArtMetro</h1>
                <p className="text-[var(--color-text-muted)] mt-1 text-sm">
                    Find art displayed at venues across the city.
                </p>
            </div>

            {isLoading && (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No venues yet"
                    description="Partner venues with displayed artworks will appear here."
                />
            )}

            {data && data.data.length > 0 && (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {data.data.map((venue) => (
                        <Link
                            key={venue.id}
                            to={`/metro/${venue.slug}`}
                            className="group rounded-[var(--radius-lg)] border border-[var(--color-border)] p-5 hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
                        >
                            <div className="flex items-start justify-between gap-2 mb-2">
                                <h3 className="font-medium group-hover:underline leading-tight">{venue.name}</h3>
                                <Badge variant={venue.status === 'active' ? 'default' : 'draft'}>
                                    {venue.status === 'active' ? 'Open' : 'Closed'}
                                </Badge>
                            </div>
                            {venue.city && (
                                <p className="text-sm text-[var(--color-text-muted)]">{venue.city}</p>
                            )}
                            {venue.address && (
                                <p className="text-xs text-[var(--color-text-faint)] mt-0.5 truncate">{venue.address}</p>
                            )}
                            {venue.description && (
                                <p className="text-xs text-[var(--color-text-faint)] mt-2 line-clamp-2">
                                    {venue.description}
                                </p>
                            )}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
