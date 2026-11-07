import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaginatedResponse, Publication } from '@/lib/api';
import { Badge, EmptyState, Spinner } from '@/components/ui';

function formatEur(cents: number, currency = 'EUR'): string {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(cents / 100);
}

function AccessBadge({ access, isFree, priceCents, currency }: {
    access?: string;
    isFree: boolean;
    priceCents: number;
    currency: string;
}) {
    if (access === 'purchased') return <Badge variant="sold">Purchased</Badge>;
    if (isFree || access === 'free') return <Badge variant="default">Free</Badge>;
    return <span className="text-sm font-medium">{formatEur(priceCents, currency)}</span>;
}

function BookCoverPlaceholder({ className = '' }: { className?: string }) {
    return (
        <div className={`bg-[var(--color-bg-muted)] flex items-center justify-center ${className}`}>
            <svg viewBox="0 0 32 48" className="w-8 h-12 text-[var(--color-text-faint)]" fill="currentColor" aria-hidden="true">
                <rect x="2" y="2" width="28" height="44" rx="2" fill="none" stroke="currentColor" strokeWidth="2"/>
                <line x1="6" y1="10" x2="26" y2="10" stroke="currentColor" strokeWidth="1.5"/>
                <line x1="6" y1="15" x2="26" y2="15" stroke="currentColor" strokeWidth="1.5"/>
                <line x1="6" y1="20" x2="20" y2="20" stroke="currentColor" strokeWidth="1.5"/>
            </svg>
        </div>
    );
}

function FeaturedBookCard({ pub }: { pub: Publication }) {
    const authorName = pub.book_authors?.[0]?.author?.name ?? null;

    return (
        <Link
            to={`/library/${pub.slug}`}
            className="group flex-none w-40 flex flex-col rounded-[var(--radius-lg)] border border-[var(--color-border)] overflow-hidden hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
            aria-label={pub.title}
        >
            <BookCoverPlaceholder className="aspect-[2/3] w-full" />
            <div className="p-2.5 flex flex-col gap-1">
                <p className="text-xs font-medium leading-tight line-clamp-2 group-hover:underline">{pub.title}</p>
                {authorName && (
                    <p className="text-xs text-[var(--color-text-faint)] truncate">{authorName}</p>
                )}
                <AccessBadge
                    access={pub.access}
                    isFree={pub.is_free}
                    priceCents={pub.price_cents}
                    currency={pub.currency}
                />
            </div>
        </Link>
    );
}

function PublicationRow({ pub }: { pub: Publication }) {
    const authorName = pub.book_authors?.[0]?.author?.name ?? null;

    return (
        <Link
            to={`/library/${pub.slug}`}
            className="group flex gap-4 rounded-[var(--radius-lg)] border border-[var(--color-border)] p-4 hover:border-[var(--color-border-strong)] transition-colors bg-[var(--color-bg)]"
        >
            <BookCoverPlaceholder className="w-16 shrink-0 aspect-[2/3] rounded-[var(--radius-sm)] overflow-hidden" />
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
                    {pub.language && (
                        <span className="text-xs text-[var(--color-text-faint)] uppercase">{pub.language}</span>
                    )}
                    <span className="ml-auto">
                        <AccessBadge
                            access={pub.access}
                            isFree={pub.is_free}
                            priceCents={pub.price_cents}
                            currency={pub.currency}
                        />
                    </span>
                </div>
            </div>
        </Link>
    );
}

export default function LibraryHomePage() {
    const [searchInput, setSearchInput] = useState('');
    const [searchQ, setSearchQ] = useState('');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        const q = searchInput.trim();
        if (q) {
            setSearchQ(q);
        } else {
            setSearchQ('');
        }
    };

    const { data: featured, isLoading: featuredLoading } = useQuery({
        queryKey: ['publications', 'featured'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<Publication>>('/books?featured=1&per_page=12');
            return res.data.data;
        },
    });

    const { data: all, isLoading: allLoading } = useQuery({
        queryKey: ['publications', 'all', searchQ],
        queryFn: async () => {
            const params = new URLSearchParams({ per_page: '24' });
            if (searchQ) params.set('q', searchQ);
            const res = await api.get<PaginatedResponse<Publication>>(`/books?${params}`);
            return res.data;
        },
    });

    const hasFeatured = featured && featured.length > 0;

    return (
        <div className="space-y-10">
            {/* Hero */}
            <section className="rounded-[var(--radius-lg)] bg-[var(--color-bg-subtle,var(--color-bg-muted))] border border-[var(--color-border)] px-8 py-12 text-center">
                <h1 className="text-4xl font-semibold tracking-tight mb-3">Digital Library</h1>
                <p className="text-[var(--color-text-muted)] text-lg max-w-xl mx-auto mb-8">
                    Catalogues, monographs, and art publications — read, collect, support culture.
                </p>
                <form onSubmit={handleSearch} className="flex gap-2 max-w-md mx-auto" role="search">
                    <input
                        type="search"
                        value={searchInput}
                        onChange={e => setSearchInput(e.target.value)}
                        placeholder="Search books, authors…"
                        aria-label="Search publications"
                        className="flex-1 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-bg)] px-4 py-2.5 text-sm placeholder:text-[var(--color-text-faint)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent,currentColor)]"
                    />
                    <button
                        type="submit"
                        className="px-5 py-2.5 rounded-[var(--radius-md)] bg-[var(--color-text)] text-[var(--color-bg)] text-sm font-medium hover:opacity-80 transition-opacity"
                    >
                        Search
                    </button>
                </form>
                {searchQ && (
                    <button
                        onClick={() => { setSearchQ(''); setSearchInput(''); }}
                        className="mt-3 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] underline"
                    >
                        Clear search
                    </button>
                )}
            </section>

            {/* Featured books — only shown when no active search */}
            {!searchQ && (
                <section aria-labelledby="featured-heading">
                    <div className="flex items-center justify-between mb-4">
                        <h2 id="featured-heading" className="text-xl font-semibold">Featured</h2>
                    </div>

                    {featuredLoading && (
                        <div className="flex justify-center py-8">
                            <Spinner size="lg" />
                        </div>
                    )}

                    {!featuredLoading && !hasFeatured && (
                        <p className="text-sm text-[var(--color-text-faint)]">No featured publications yet.</p>
                    )}

                    {hasFeatured && (
                        <div
                            className="flex gap-4 overflow-x-auto pb-2 -mx-1 px-1"
                            role="list"
                            aria-label="Featured publications"
                        >
                            {featured.map(pub => (
                                <div key={pub.id} role="listitem">
                                    <FeaturedBookCard pub={pub} />
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            )}

            {/* All publications */}
            <section aria-labelledby="all-heading">
                <div className="flex items-center justify-between mb-4">
                    <h2 id="all-heading" className="text-xl font-semibold">
                        {searchQ ? `Results for "${searchQ}"` : 'All publications'}
                    </h2>
                    {all && (
                        <span className="text-sm text-[var(--color-text-faint)]">
                            {all.meta.total} {all.meta.total === 1 ? 'title' : 'titles'}
                        </span>
                    )}
                </div>

                {allLoading && (
                    <div className="flex justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                )}

                {all && all.data.length === 0 && (
                    <EmptyState
                        title={searchQ ? 'No results' : 'No publications yet'}
                        description={
                            searchQ
                                ? `Nothing matched "${searchQ}". Try different keywords.`
                                : 'Books, catalogues, and digital publications will appear here.'
                        }
                    />
                )}

                {all && all.data.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {all.data.map(pub => (
                            <PublicationRow key={pub.id} pub={pub} />
                        ))}
                    </div>
                )}

                {all && all.meta.last_page > 1 && (
                    <p className="text-sm text-[var(--color-text-faint)] text-center mt-6">
                        Showing {all.data.length} of {all.meta.total} — pagination coming soon
                    </p>
                )}
            </section>
        </div>
    );
}
