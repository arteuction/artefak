import { Link } from 'react-router-dom';
import type { Artwork } from '@/lib/api';

type Props = { artwork: Artwork };

export default function ArtworkCard({ artwork }: Props) {
    const statusLabel: Record<string, string> = {
        draft: 'Draft',
        listed: 'For Sale',
        in_auction: 'In Auction',
        sold: 'Sold',
        archived: 'Archived',
    };

    return (
        <Link
            to={`/artworks/${artwork.slug}`}
            className="group block overflow-hidden rounded-lg border border-gray-200 hover:border-gray-400 transition-colors"
        >
            <div className="aspect-square bg-gray-100 flex items-center justify-center text-gray-300 text-4xl">
                🖼
            </div>
            <div className="p-4">
                <p className="text-xs text-gray-400 mb-1">{artwork.artist.name}</p>
                <h3 className="font-medium text-sm leading-snug group-hover:underline">
                    {artwork.title}
                </h3>
                {artwork.year_created && (
                    <p className="text-xs text-gray-500 mt-1">{artwork.year_created}</p>
                )}
                <span className="inline-block mt-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                    {statusLabel[artwork.status] ?? artwork.status}
                </span>
            </div>
        </Link>
    );
}
