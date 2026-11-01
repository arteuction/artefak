import { useState } from 'react';
import { Link } from 'react-router-dom';
import type { Artwork } from '@/lib/api';
import { imageUrl } from '@/lib/api';
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

function ArtworkImage({ artwork }: { artwork: Artwork }) {
    const [loaded, setLoaded] = useState(false);

    const d = artwork.image_derivatives;
    const thumb  = imageUrl(d?.thumb);
    const medium = imageUrl(d?.medium);
    const large  = imageUrl(d?.large);

    if (!thumb && !medium) {
        return (
            <div
                className="aspect-artwork bg-[var(--color-bg-subtle)] flex items-center justify-center"
                aria-label="No image available"
            >
                <svg
                    className="h-10 w-10 text-[var(--color-border-strong)]"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={1}
                    aria-hidden="true"
                >
                    <rect x="3" y="3" width="18" height="18" rx="1" />
                    <circle cx="8.5" cy="8.5" r="1.5" />
                    <path d="M21 15l-5-5L5 21" />
                </svg>
            </div>
        );
    }

    return (
        <div className="aspect-artwork relative overflow-hidden bg-[var(--color-bg-muted)]">
            {/* Thumbnail placeholder — already in browser cache from list prefetch */}
            {thumb && (
                <img
                    src={thumb}
                    alt=""
                    aria-hidden="true"
                    className="absolute inset-0 w-full h-full object-cover scale-105 blur-sm"
                    style={{ transition: `opacity ${loaded ? '0ms' : '0ms'}` }}
                />
            )}

            {/* Full-resolution image — requested immediately, fades in when decoded */}
            {(medium || large) && (
                <img
                    src={medium ?? large!}
                    srcSet={
                        [
                            medium ? `${medium} 800w` : null,
                            large  ? `${large} 1600w` : null,
                        ]
                            .filter(Boolean)
                            .join(', ')
                    }
                    sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
                    alt={artwork.title}
                    loading="lazy"
                    decoding="async"
                    onLoad={() => setLoaded(true)}
                    className="absolute inset-0 w-full h-full object-cover"
                    style={{
                        opacity: loaded ? 1 : 0,
                        transition: 'opacity 300ms ease-out',
                    }}
                />
            )}
        </div>
    );
}

export default function ArtworkCard({ artwork }: Props) {
    const variant = STATUS_VARIANT[artwork.status] ?? 'default';
    const label   = STATUS_LABEL[artwork.status]  ?? artwork.status;

    const editionNote =
        !artwork.is_original && artwork.edition_number && artwork.edition_total
            ? `Ed. ${artwork.edition_number}/${artwork.edition_total}`
            : null;

    return (
        <Link
            to={`/artworks/${artwork.slug}`}
            className="group block overflow-hidden rounded-[var(--radius-lg)] border border-[var(--color-border)] hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
            aria-label={`${artwork.title}${artwork.artist?.name ? ` by ${artwork.artist.name}` : ''}`}
        >
            <ArtworkImage artwork={artwork} />

            <div className="p-4">
                {/* Cultural metadata — editorial type */}
                <p
                    className="text-xs text-[var(--color-text-faint)] mb-0.5 truncate"
                    style={{ fontFamily: 'var(--font-ui)' }}
                >
                    {artwork.artist.name}
                </p>
                <h3
                    className="leading-snug group-hover:underline line-clamp-2"
                    style={{
                        fontFamily: 'var(--font-editorial)',
                        fontSize: '1.0625rem',
                        fontWeight: 400,
                        color: 'var(--color-text)',
                    }}
                >
                    {artwork.title}
                </h3>
                <p
                    className="text-xs text-[var(--color-text-muted)] mt-0.5"
                    style={{ fontFamily: 'var(--font-ui)' }}
                >
                    {[artwork.year_created, artwork.medium, editionNote]
                        .filter(Boolean)
                        .join(' · ')}
                </p>

                {/* Commercial metadata — separated visually */}
                <div className="mt-3 flex items-center justify-between gap-2">
                    <Badge variant={variant}>{label}</Badge>
                </div>
            </div>
        </Link>
    );
}
