import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Artwork, PaginatedResponse } from '@/lib/api';
import ArtworkCard from '@/components/ArtworkCard';

const STATUSES = ['listed', 'in_auction'] as const;

export default function ArtworkListPage() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [medium, setMedium] = useState('');

    const { data, isLoading, isError } = useQuery({
        queryKey: ['artworks', page, search, medium],
        queryFn: async () => {
            const params: Record<string, string | number> = { page };
            if (search) params.search = search;
            if (medium) params.medium = medium;
            const res = await api.get<PaginatedResponse<Artwork>>('/artworks', { params });
            return res.data;
        },
    });

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">Artworks</h1>

            <div className="flex gap-3 mb-6">
                <input
                    type="search"
                    placeholder="Search artworks…"
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                    className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                />
                <select
                    value={medium}
                    onChange={(e) => { setMedium(e.target.value); setPage(1); }}
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                >
                    <option value="">All media</option>
                    <option value="painting">Painting</option>
                    <option value="sculpture">Sculpture</option>
                    <option value="photography">Photography</option>
                    <option value="digital">Digital</option>
                    <option value="nft">NFT</option>
                    <option value="mixed">Mixed</option>
                </select>
            </div>

            {isLoading && (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    {Array.from({ length: 8 }).map((_, i) => (
                        <div key={i} className="rounded-lg border border-gray-100 animate-pulse">
                            <div className="aspect-square bg-gray-100" />
                            <div className="p-4 space-y-2">
                                <div className="h-3 bg-gray-100 rounded w-1/2" />
                                <div className="h-4 bg-gray-100 rounded w-3/4" />
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {isError && (
                <p className="text-red-600 text-sm">Failed to load artworks. Please try again.</p>
            )}

            {data && (
                <>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {data.data.map((artwork) => (
                            <ArtworkCard key={artwork.id} artwork={artwork} />
                        ))}
                    </div>

                    {data.data.length === 0 && (
                        <p className="text-gray-500 text-sm text-center py-12">No artworks found.</p>
                    )}

                    {data.meta.last_page > 1 && (
                        <div className="flex justify-center gap-2 mt-8">
                            <button
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={page === 1}
                                className="px-4 py-2 rounded-md border border-gray-300 text-sm disabled:opacity-40 hover:bg-gray-50"
                            >
                                Previous
                            </button>
                            <span className="px-4 py-2 text-sm text-gray-500">
                                {page} / {data.meta.last_page}
                            </span>
                            <button
                                onClick={() => setPage((p) => Math.min(data.meta.last_page, p + 1))}
                                disabled={page === data.meta.last_page}
                                className="px-4 py-2 rounded-md border border-gray-300 text-sm disabled:opacity-40 hover:bg-gray-50"
                            >
                                Next
                            </button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
