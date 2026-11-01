import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Venue, VenueArtwork } from '@/lib/api';
import { EmptyState, Spinner } from '@/components/ui';

type VenueWithArtworks = Venue & { artworks?: VenueArtwork[] };

export default function VenueDetailPage() {
    const { slug } = useParams<{ slug: string }>();

    const { data: venue, isLoading } = useQuery({
        queryKey: ['venue', slug],
        queryFn: async () => {
            const res = await api.get<VenueWithArtworks>(`/venues/${slug}`);
            return res.data;
        },
        enabled: !!slug,
    });

    if (isLoading) {
        return (
            <div className="flex justify-center py-24">
                <Spinner size="lg" />
            </div>
        );
    }

    if (!venue) {
        return <p className="text-red-600">Venue not found.</p>;
    }

    const artworks = venue.artworks ?? [];

    return (
        <div className="max-w-3xl">
            {/* Breadcrumb */}
            <nav className="text-sm text-[var(--color-text-faint)] mb-4 flex items-center gap-1.5">
                <Link to="/metro" className="hover:text-[var(--color-text)] transition-colors">
                    ArtMetro
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">{venue.name}</span>
            </nav>

            <h1 className="text-3xl font-semibold mb-1">{venue.name}</h1>
            {venue.city && (
                <p className="text-[var(--color-text-muted)] text-sm mb-1">{venue.city}</p>
            )}
            {venue.address && (
                <p className="text-xs text-[var(--color-text-faint)] mb-4">{venue.address}</p>
            )}

            {/* Map link — uses native OSM deep link, no Leaflet package required */}
            {venue.lat !== null && venue.lng !== null && (
                <a
                    href={`https://www.openstreetmap.org/?mlat=${venue.lat}&mlon=${venue.lng}#map=17/${venue.lat}/${venue.lng}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 text-sm text-[var(--color-accent)] hover:underline mb-4"
                >
                    <svg viewBox="0 0 20 20" className="w-4 h-4" fill="currentColor">
                        <path fillRule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C14.902 15.35 16.5 13.03 16.5 10a6.5 6.5 0 00-13 0c0 3.03 1.598 5.35 3.853 7.583a22.952 22.952 0 002.274 1.765 11.88 11.88 0 00.757.433 5.738 5.738 0 00.281.14l.018.008.006.003zM10 11.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" clipRule="evenodd" />
                    </svg>
                    View on map
                </a>
            )}

            {venue.description && (
                <p className="text-sm text-[var(--color-text-muted)] leading-relaxed mb-6">
                    {venue.description}
                </p>
            )}

            {venue.website_url && (
                <a
                    href={venue.website_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-sm text-[var(--color-accent)] hover:underline mb-6 block"
                >
                    Visit venue website →
                </a>
            )}

            <h2 className="text-lg font-medium mb-3">Artworks on display</h2>

            {artworks.length === 0 ? (
                <EmptyState
                    title="No artworks listed"
                    description="Artworks displayed at this venue will appear here."
                />
            ) : (
                <div className="space-y-2">
                    {artworks.map((va) => (
                        <div
                            key={va.id}
                            className="flex items-center justify-between gap-4 rounded-[var(--radius-md)] border border-[var(--color-border)] px-4 py-3 bg-[var(--color-bg)]"
                        >
                            <div className="min-w-0">
                                <Link
                                    to={`/artworks/${va.artwork.slug}`}
                                    className="font-medium hover:underline truncate block"
                                >
                                    {va.artwork.title}
                                </Link>
                                <div className="flex items-center gap-2 text-xs text-[var(--color-text-muted)] mt-0.5">
                                    <span>{va.artwork.artist.name}</span>
                                    {va.artwork.medium && <span>· {va.artwork.medium}</span>}
                                    {va.location_label && (
                                        <span className="text-[var(--color-text-faint)]">· {va.location_label}</span>
                                    )}
                                </div>
                            </div>
                            {va.iiif_manifest_url && (
                                <a
                                    href={`https://universalviewer.io/uv.html#?manifest=${encodeURIComponent(va.iiif_manifest_url)}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="shrink-0 text-xs text-[var(--color-accent)] hover:underline"
                                >
                                    View IIIF
                                </a>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
