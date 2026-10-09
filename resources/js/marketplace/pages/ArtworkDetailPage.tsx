import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Artwork } from '@/lib/api';

export default function ArtworkDetailPage() {
    const { slug } = useParams<{ slug: string }>();

    const { data: artwork, isLoading, isError } = useQuery({
        queryKey: ['artwork', slug],
        queryFn: async () => {
            const res = await api.get<Artwork>(`/artworks/${slug}`);
            return res.data;
        },
        enabled: !!slug,
    });

    if (isLoading) {
        return (
            <div className="animate-pulse space-y-4">
                <div className="h-8 bg-gray-100 rounded w-1/3" />
                <div className="aspect-video bg-gray-100 rounded-lg" />
            </div>
        );
    }

    if (isError || !artwork) {
        return <p className="text-red-600">Artwork not found.</p>;
    }

    const meta = [
        artwork.medium && `Medium: ${artwork.medium}`,
        artwork.dimensions && `Dimensions: ${artwork.dimensions}`,
        artwork.year_created && `Year: ${artwork.year_created}`,
        artwork.is_original ? 'Original' : artwork.edition_number != null
            ? `Edition ${artwork.edition_number}/${artwork.edition_total}`
            : null,
    ].filter(Boolean);

    const statusBadge: Record<string, string> = {
        listed: 'bg-green-100 text-green-800',
        in_auction: 'bg-blue-100 text-blue-800',
        sold: 'bg-gray-100 text-gray-600',
        draft: 'bg-yellow-100 text-yellow-800',
        archived: 'bg-gray-100 text-gray-400',
    };

    return (
        <div className="max-w-4xl">
            <nav className="text-sm text-gray-400 mb-6">
                <Link to="/artworks" className="hover:text-black">Artworks</Link>
                <span className="mx-2">/</span>
                <span className="text-gray-700">{artwork.title}</span>
            </nav>

            <div className="grid md:grid-cols-2 gap-10">
                <div className="aspect-square bg-gray-100 rounded-lg flex items-center justify-center text-gray-300 text-8xl">
                    🖼
                </div>

                <div>
                    <Link
                        to={`/artists/${artwork.artist.id}`}
                        className="text-sm text-gray-500 hover:text-black"
                    >
                        {artwork.artist.name}
                    </Link>
                    <h1 className="text-3xl font-semibold mt-1 mb-3">{artwork.title}</h1>

                    <span className={`inline-block rounded-full px-3 py-1 text-xs font-medium mb-4 ${statusBadge[artwork.status] ?? 'bg-gray-100 text-gray-600'}`}>
                        {artwork.status.replace('_', ' ')}
                    </span>

                    {meta.length > 0 && (
                        <ul className="text-sm text-gray-600 space-y-1 mb-4">
                            {meta.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    )}

                    {artwork.description && (
                        <p className="text-sm text-gray-700 leading-relaxed">{artwork.description}</p>
                    )}
                </div>
            </div>
        </div>
    );
}
