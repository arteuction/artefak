import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ArtistPortfolio } from '@/lib/api';
import ArtworkCard from '@/components/ArtworkCard';
import { EmptyState, Spinner } from '@/components/ui';
import { useDocTitle } from '@/lib/useDocTitle';

export default function ArtistProfilePage() {
    const { slug } = useParams<{ slug: string }>();

    const { data, isLoading, isError } = useQuery({
        queryKey: ['artist', slug],
        queryFn: async () => {
            const res = await api.get<ArtistPortfolio>(`/artists/${slug}`);
            return res.data;
        },
        enabled: !!slug,
    });

    useDocTitle(data?.profile.display_name ?? null);

    if (isLoading) {
        return (
            <div className="flex justify-center py-24">
                <Spinner size="lg" />
            </div>
        );
    }

    if (isError || !data) {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)]">Artist profile not found.</p>
                <Link
                    to="/artworks"
                    className="mt-4 inline-block text-sm text-[var(--color-accent)] hover:underline"
                >
                    Browse artworks
                </Link>
            </div>
        );
    }

    const { profile, artworks, recent_sales } = data;

    return (
        <div className="max-w-4xl">
            <nav className="text-sm text-[var(--color-text-faint)] mb-6 flex items-center gap-1.5">
                <Link to="/artworks" className="hover:text-[var(--color-text)] transition-colors">
                    Artworks
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">{profile.display_name}</span>
            </nav>

            {/* Header */}
            <div className="flex items-start gap-5 mb-8">
                <div className="h-16 w-16 shrink-0 rounded-full bg-[var(--color-bg-muted)] flex items-center justify-center">
                    <svg className="h-7 w-7 text-[var(--color-text-faint)]" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" />
                    </svg>
                </div>
                <div className="flex-1 min-w-0">
                    <h1 className="text-2xl font-semibold">{profile.display_name}</h1>
                    <div className="flex items-center gap-4 mt-1.5 flex-wrap">
                        {profile.website && (
                            <a
                                href={profile.website}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm text-[var(--color-accent)] hover:underline"
                            >
                                Website
                            </a>
                        )}
                        {profile.instagram_handle && (
                            <a
                                href={`https://instagram.com/${profile.instagram_handle}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
                            >
                                @{profile.instagram_handle}
                            </a>
                        )}
                    </div>
                    {profile.bio && (
                        <p className="text-sm text-[var(--color-text-muted)] mt-3 leading-relaxed max-w-prose">
                            {profile.bio}
                        </p>
                    )}
                </div>
            </div>

            {/* Listed artworks */}
            <section>
                <h2 className="text-lg font-medium mb-4 text-[var(--color-text)]">
                    Works{' '}
                    <span className="text-[var(--color-text-faint)] font-normal text-sm">
                        ({artworks.meta.total})
                    </span>
                </h2>

                {artworks.data.length === 0 ? (
                    <EmptyState
                        title="No published works yet"
                        description="This artist hasn't listed any artworks."
                    />
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        {artworks.data.map((artwork) => (
                            <ArtworkCard key={artwork.id} artwork={artwork} />
                        ))}
                    </div>
                )}
            </section>

            {/* Recent auction results */}
            {recent_sales.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-lg font-medium mb-4 text-[var(--color-text)]">
                        Recent auction results
                    </h2>
                    <div className="space-y-2">
                        {recent_sales.map((sale) => (
                            <div
                                key={sale.auction_item_id}
                                className="flex items-center justify-between rounded-[var(--radius-md)] border border-[var(--color-border)] px-4 py-3 text-sm bg-[var(--color-bg)]"
                            >
                                <Link
                                    to={`/artworks/${sale.slug}`}
                                    className="font-medium hover:underline truncate"
                                >
                                    {sale.title}
                                </Link>
                                <span className="text-xs text-[var(--color-sold-text)] ml-4 shrink-0 font-medium">
                                    Sold
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
