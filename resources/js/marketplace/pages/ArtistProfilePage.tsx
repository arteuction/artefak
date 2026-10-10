import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Artwork, PaginatedResponse } from '@/lib/api';
import ArtworkCard from '@/components/ArtworkCard';

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
            <nav className="text-sm text-gray-400 mb-6">
                <Link to="/artworks" className="hover:text-black">Artworks</Link>
                <span className="mx-2">/</span>
                <span className="text-gray-700">{artistName ?? 'Artist'}</span>
            </nav>

            <div className="flex items-center gap-4 mb-8">
                <div className="w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center text-2xl">
                    👤
                </div>
                <div>
                    {isLoading ? (
                        <div className="h-6 w-32 bg-gray-100 animate-pulse rounded" />
                    ) : (
                        <h1 className="text-2xl font-semibold">{artistName ?? 'Artist'}</h1>
                    )}
                </div>
            </div>

            {isLoading && (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="rounded-lg border border-gray-100 animate-pulse">
                            <div className="aspect-square bg-gray-100" />
                            <div className="p-4 space-y-2">
                                <div className="h-4 bg-gray-100 rounded w-3/4" />
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {artworks && (
                <>
                    <h2 className="text-lg font-medium mb-4">
                        Works ({artworks.meta.total})
                    </h2>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {artworks.data.map((artwork) => (
                            <ArtworkCard key={artwork.id} artwork={artwork} />
                        ))}
                    </div>
                    {artworks.data.length === 0 && (
                        <p className="text-gray-500 text-sm">No artworks published yet.</p>
                    )}
                </>
            )}
        </div>
    );
}
