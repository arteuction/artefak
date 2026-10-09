import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import type { Artwork, PaginatedResponse } from '@/lib/api';
import ArtworkCard from '@/components/ArtworkCard';

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
                <p className="text-gray-500 mb-4">You need to sign in to access your dashboard.</p>
                <Link to="/login" className="rounded-md bg-black text-white px-5 py-2 text-sm font-medium hover:bg-gray-800">
                    Sign in
                </Link>
            </div>
        );
    }

    if (user.role !== 'artist') {
        return <p className="text-gray-500">Dashboard is available for artists only.</p>;
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-8">
                <h1 className="text-3xl font-semibold">My artworks</h1>
                <Link
                    to="/dashboard/artworks/new"
                    className="rounded-md bg-black text-white px-5 py-2 text-sm font-medium hover:bg-gray-800"
                >
                    + Add artwork
                </Link>
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

            {data && (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    {data.data.map((artwork) => (
                        <ArtworkCard key={artwork.id} artwork={artwork} />
                    ))}
                    {data.data.length === 0 && (
                        <p className="col-span-full text-gray-500 text-sm text-center py-12">
                            No artworks yet.{' '}
                            <Link to="/dashboard/artworks/new" className="underline hover:no-underline">
                                Add your first one.
                            </Link>
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
