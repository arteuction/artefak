import { useParams, Link, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, useEffect } from 'react';
import { api } from '@/lib/api';
import type { Publication } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { useDocTitle } from '@/lib/useDocTitle';
import { Badge, Button, Spinner } from '@/components/ui';

function formatEur(cents: number, currency = 'EUR'): string {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(cents / 100);
}

export default function PublicationDetailPage() {
    const { slug } = useParams<{ slug: string }>();
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [searchParams] = useSearchParams();
    const [purchasing, setPurchasing] = useState(false);
    const paymentResult = searchParams.get('payment');

    // Refresh entitlement after returning from Stripe Checkout
    useEffect(() => {
        if (paymentResult === 'success') {
            void queryClient.invalidateQueries({ queryKey: ['publication', slug] });
        }
    }, [paymentResult, slug, queryClient]);

    const { data: pub, isLoading } = useQuery({
        queryKey: ['publication', slug],
        queryFn: async () => {
            const res = await api.get<Publication>(`/books/${slug}`);
            return res.data;
        },
        enabled: !!slug,
    });

    const purchaseMutation = useMutation({
        mutationFn: async () => {
            const res = await api.post<{ url?: string; already_purchased?: boolean }>(
                `/books/${pub!.slug}/checkout-session`,
            );
            return res.data;
        },
        onSuccess: (data) => {
            if (data.already_purchased) {
                void queryClient.invalidateQueries({ queryKey: ['publication', slug] });
            } else if (data.url) {
                window.location.href = data.url;
            }
            setPurchasing(false);
        },
        onError: () => setPurchasing(false),
    });

    useDocTitle(pub?.title ?? null);

    const downloadMutation = useMutation({
        mutationFn: async () => {
            const res = await api.get<{ url: string }>(`/my-books/${pub!.id}/download-url`);
            return res.data;
        },
        onSuccess: ({ url }) => {
            const a = document.createElement('a');
            a.href = url;
            a.download = pub?.title ?? 'publication';
            a.click();
        },
    });

    if (isLoading) {
        return (
            <div className="flex justify-center py-24">
                <Spinner size="lg" />
            </div>
        );
    }

    if (!pub) {
        return <p className="text-red-600">Publication not found.</p>;
    }

    const isFree = pub.is_free || pub.price_cents === 0;
    const hasPurchased = pub.access === 'purchased' || pub.access === 'free' || isFree;
    const canDownload = hasPurchased && pub.status === 'published';

    return (
        <div className="max-w-3xl">
            {/* Breadcrumb */}
            <nav className="text-sm text-[var(--color-text-faint)] mb-4 flex items-center gap-1.5">
                <Link to="/library" className="hover:text-[var(--color-text)] transition-colors">
                    Library
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">{pub.title}</span>
            </nav>

            <div className="flex gap-8 flex-col sm:flex-row">
                {/* Cover placeholder */}
                <div className="w-40 shrink-0 aspect-[2/3] rounded-[var(--radius-md)] overflow-hidden bg-[var(--color-bg-muted)] flex items-center justify-center self-start">
                    <svg viewBox="0 0 64 96" className="w-16 h-24 text-[var(--color-text-faint)]" fill="currentColor">
                        <rect x="4" y="4" width="56" height="88" rx="4" fill="none" stroke="currentColor" strokeWidth="3"/>
                        <line x1="12" y1="20" x2="52" y2="20" stroke="currentColor" strokeWidth="2"/>
                        <line x1="12" y1="30" x2="52" y2="30" stroke="currentColor" strokeWidth="2"/>
                        <line x1="12" y1="40" x2="40" y2="40" stroke="currentColor" strokeWidth="2"/>
                    </svg>
                </div>

                {/* Content */}
                <div className="flex-1 min-w-0">
                    <div className="flex items-start gap-3 mb-1">
                        <h1 className="text-2xl font-semibold flex-1">{pub.title}</h1>
                        {pub.status === 'published' && (
                            <Badge variant="default">Published</Badge>
                        )}
                    </div>

                    {pub.book_authors && pub.book_authors.length > 0 && (
                        <p className="text-[var(--color-text-muted)] mb-4">
                            {pub.book_authors[0].author.name}
                        </p>
                    )}

                    {pub.description && (
                        <p className="text-sm text-[var(--color-text-muted)] leading-relaxed mb-5">
                            {pub.description}
                        </p>
                    )}

                    {/* Metadata */}
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm mb-6">
                        {pub.page_count && (
                            <>
                                <dt className="text-[var(--color-text-faint)]">Pages</dt>
                                <dd className="font-medium">{pub.page_count}</dd>
                            </>
                        )}
                        <dt className="text-[var(--color-text-faint)]">Price</dt>
                        <dd className="font-medium">
                            {isFree ? 'Free' : formatEur(pub.price_cents, pub.currency)}
                        </dd>
                    </dl>

                    {/* CTA */}
                    {canDownload ? (
                        <div className="flex items-center gap-3">
                            <Button
                                loading={downloadMutation.isPending}
                                onClick={() => downloadMutation.mutate()}
                            >
                                Download
                            </Button>
                            {pub.access === 'purchased' && (
                                <span className="text-sm text-[var(--color-text-muted)]">
                                    You own this publication.
                                </span>
                            )}
                        </div>
                    ) : user ? (
                        <div className="space-y-3">
                            {!purchasing ? (
                                <Button onClick={() => setPurchasing(true)}>
                                    Buy for {formatEur(pub.price_cents, pub.currency)}
                                </Button>
                            ) : (
                                <div className="rounded-[var(--radius-md)] border border-[var(--color-border)] p-4 space-y-3">
                                    <p className="text-sm font-medium">
                                        Confirm purchase: {formatEur(pub.price_cents, pub.currency)}
                                    </p>
                                    <p className="text-xs text-[var(--color-text-muted)]">
                                        Payment will be processed via your saved payment method.
                                    </p>
                                    <div className="flex gap-2">
                                        <Button
                                            loading={purchaseMutation.isPending}
                                            onClick={() => purchaseMutation.mutate()}
                                        >
                                            Confirm
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            disabled={purchaseMutation.isPending}
                                            onClick={() => setPurchasing(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                    {purchaseMutation.isError && (
                                        <p className="text-xs text-red-600">Purchase failed. Please try again.</p>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="flex items-center gap-3">
                            <Link
                                to="/login"
                                className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                            >
                                Sign in to purchase
                            </Link>
                        </div>
                    )}

                    {downloadMutation.isError && (
                        <p className="text-xs text-red-600 mt-2">Download failed. Please try again.</p>
                    )}
                </div>
            </div>
        </div>
    );
}
