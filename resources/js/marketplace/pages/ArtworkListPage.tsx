import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Artwork, PaginatedResponse } from '@/lib/api';
import ArtworkCard from '@/components/ArtworkCard';
import { Button, EmptyState, Input, Spinner } from '@/components/ui';

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
                <Input
                    type="search"
                    placeholder="Search artworks…"
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                    className="flex-1"
                />
                <select
                    value={medium}
                    onChange={(e) => { setMedium(e.target.value); setPage(1); }}
                    className="rounded-[var(--radius-sm)] border border-[var(--color-border)] px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
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
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {isError && (
                <p className="text-red-600 text-sm">Failed to load artworks. Please try again.</p>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No artworks found"
                    description={search || medium ? 'Try different search terms or filters.' : 'Artworks will appear here once artists submit their work.'}
                />
            )}

            {data && data.data.length > 0 && (
                <>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {data.data.map((artwork) => (
                            <ArtworkCard key={artwork.id} artwork={artwork} />
                        ))}
                    </div>

                    {data.meta.last_page > 1 && (
                        <div className="flex justify-center items-center gap-3 mt-8">
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={page === 1}
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
                                disabled={page === data.meta.last_page}
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
