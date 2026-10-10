import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Artwork, PaginatedResponse } from '@/lib/api';
import ArtworkCard from '@/components/ArtworkCard';
import { EmptyState, Spinner } from '@/components/ui';

export default function ArtistProfilePage() {
    const { id } = useParams<{ id: string }>();

    const { data: artworks, isLoading } = useQuery({
        queryKey: ['artist-artworks', id],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Artwork>>('/artworks', {
                params: { artist_id: id },
            });
            return res.data;
        },
        enabled: !!id,
    });

    const artistName = artworks?.data[0]?.artist?.name;

    return (
        <div>
            <nav className="text-sm text-[var(--color-text-faint)] mb-6 flex items-center gap-1.5">
                <Link to="/artworks" className="hover:text-[var(--color-text)] transition-colors">
                    Artworks
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">
                    {artistName ?? 'Artist'}
                </span>
            </nav>

            <div className="flex items-center gap-4 mb-8">
                <div className="h-14 w-14 rounded-full bg-[var(--color-bg-muted)] flex items-center justify-center">
                    <svg className="h-6 w-6 text-[var(--color-text-faint)]" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" />
                    </svg>
                </div>
                <div>
                    {isLoading ? (
                        <div className="h-7 w-36 bg-[var(--color-bg-muted)] animate-pulse rounded" />
                    ) : (
                        <h1 className="text-2xl font-semibold">{artistName ?? 'Artist'}</h1>
                    )}
                </div>
            </div>

            {isLoading && (
                <div className="flex justify-center py-12">
                    <Spinner size="lg" />
                </div>
            )}

            {artworks && artworks.data.length === 0 && (
                <EmptyState
                    title="No published works yet"
                    description="This artist hasn't submitted any artworks."
                />
            )}

            {artworks && artworks.data.length > 0 && (
                <>
                    <h2 className="text-lg font-medium mb-4 text-[var(--color-text)]">
                        Works{' '}
                        <span className="text-[var(--color-text-faint)] font-normal text-sm">
                            ({artworks.meta.total})
                        </span>
                    </h2>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {artworks.data.map((artwork) => (
                            <ArtworkCard key={artwork.id} artwork={artwork} />
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
