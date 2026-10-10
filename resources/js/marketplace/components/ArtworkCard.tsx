import { Link } from 'react-router-dom';
import type { Artwork } from '@/lib/api';
import { Badge } from '@/components/ui';

type Props = { artwork: Artwork };

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    listed:     'default',
    in_auction: 'auction',
    sold:       'sold',
    draft:      'draft',
    archived:   'draft',
};

const STATUS_LABEL: Record<string, string> = {
    listed:     'For Sale',
    in_auction: 'In Auction',
    sold:       'Sold',
    draft:      'Draft',
    archived:   'Archived',
};

export default function ArtworkCard({ artwork }: Props) {
    const variant = STATUS_VARIANT[artwork.status] ?? 'default';
    const label   = STATUS_LABEL[artwork.status] ?? artwork.status;

    return (
        <Link
            to={`/artworks/${artwork.slug}`}
            className="group block overflow-hidden rounded-[var(--radius-lg)] border border-[var(--color-border)] hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
        >
            {/* Image placeholder — real image will come in Phase 103 */}
            <div className="aspect-artwork bg-[var(--color-bg-subtle)] flex items-center justify-center overflow-hidden">
                <svg className="h-12 w-12 text-[var(--color-border-strong)]" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>

            <div className="p-4">
                <p className="text-xs text-[var(--color-text-faint)] mb-1 truncate">
                    {artwork.artist.name}
                </p>
                <h3 className="font-medium text-sm leading-snug group-hover:underline line-clamp-2">
                    {artwork.title}
                </h3>
                {artwork.year_created && (
                    <p className="text-xs text-[var(--color-text-muted)] mt-1">{artwork.year_created}</p>
                )}
                <div className="mt-2">
                    <Badge variant={variant}>{label}</Badge>
                </div>
            </div>
        </Link>
    );
}
