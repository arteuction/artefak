import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaginatedResponse, Publication } from '@/lib/api';
import { Badge, EmptyState, Spinner } from '@/components/ui';

function formatEur(cents: number, currency = 'EUR'): string {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(cents / 100);
}

function AccessBadge({ access, isFree, priceCents, currency }: { access?: string; isFree: boolean; priceCents: number; currency: string }) {
    if (access === 'purchased') return <Badge variant="sold">Purchased</Badge>;
    if (isFree || access === 'free') return <Badge variant="default">Free</Badge>;
    return <span className="text-sm font-medium text-[var(--color-text)]">{formatEur(priceCents, currency)}</span>;
}

function primaryAuthorName(pub: Publication): string | null {
    const first = pub.book_authors?.[0];
    return first?.author?.name ?? null;
}

function PublicationCard({ pub }: { pub: Publication }) {
    const authorName = primaryAuthorName(pub);

    return (
        <Link
            to={`/library/${pub.slug}`}
            className="group flex gap-4 rounded-[var(--radius-lg)] border border-[var(--color-border)] p-4 hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
        >
            {/* Cover placeholder — no cover_image_url in book model */}
            <div className="w-16 shrink-0 aspect-[2/3] rounded-[var(--radius-sm)] overflow-hidden bg-[var(--color-bg-muted)] flex items-center justify-center">
                <svg viewBox="0 0 32 48" className="w-8 h-12 text-[var(--color-text-faint)]" fill="currentColor">
                    <rect x="2" y="2" width="28" height="44" rx="2" fill="none" stroke="currentColor" strokeWidth="2"/>
                    <line x1="6" y1="10" x2="26" y2="10" stroke="currentColor" strokeWidth="1.5"/>
                    <line x1="6" y1="15" x2="26" y2="15" stroke="currentColor" strokeWidth="1.5"/>
                    <line x1="6" y1="20" x2="20" y2="20" stroke="currentColor" strokeWidth="1.5"/>
                </svg>
            </div>

            {/* Info */}
            <div className="min-w-0 flex-1">
                <h3 className="font-medium leading-tight group-hover:underline truncate">{pub.title}</h3>
                {authorName && (
                    <p className="text-sm text-[var(--color-text-muted)] mt-0.5 truncate">{authorName}</p>
                )}
                {pub.short_description && (
                    <p className="text-xs text-[var(--color-text-faint)] mt-1.5 line-clamp-2">{pub.short_description}</p>
                )}
                <div className="flex items-center gap-2 mt-2">
                    {pub.page_count && (
                        <span className="text-xs text-[var(--color-text-faint)]">{pub.page_count} pp.</span>
                    )}
                    <span className="ml-auto">
                        <AccessBadge access={pub.access} isFree={pub.is_free} priceCents={pub.price_cents} currency={pub.currency} />
                    </span>
                </div>
            </div>
        </Link>
    );
}

export default function PublicationListPage() {
    const { data, isLoading } = useQuery({
        queryKey: ['publications'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Publication>>('/books');
            return res.data;
        },
    });

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">Digital library</h1>

            {isLoading && (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No publications yet"
                    description="Books, catalogues, and digital publications will appear here."
                />
            )}

            {data && data.data.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {data.data.map((pub) => (
                        <PublicationCard key={pub.id} pub={pub} />
                    ))}
                </div>
            )}
        </div>
    );
}
