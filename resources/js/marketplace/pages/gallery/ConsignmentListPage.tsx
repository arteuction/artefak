import { Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { GalleryConsignment, PaginatedResponse } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { Badge, Button, EmptyState, Spinner } from '@/components/ui';

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    pending:   'auction',
    active:    'default',
    completed: 'sold',
    rejected:  'draft',
};

const STATUS_LABEL: Record<string, string> = {
    pending:   'Pending review',
    active:    'Active',
    completed: 'Completed',
    rejected:  'Rejected',
};

export default function ConsignmentListPage() {
    const { user } = useAuth();
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['gallery-consignments'],
        queryFn: async () => {
            const res = await api.get<PaginatedResponse<GalleryConsignment>>('/consignments');
            return res.data;
        },
        enabled: !!user,
    });

    const approveMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/consignments/${id}/approve`),
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['gallery-consignments'] }),
    });

    const rejectMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/consignments/${id}/terminate`),
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['gallery-consignments'] }),
    });

    if (!user) {
        return (
            <div className="text-center py-16">
                <p className="text-[var(--color-text-muted)] mb-4">Sign in to view consignments.</p>
                <Link
                    to="/login"
                    className="inline-flex items-center rounded-[var(--radius-sm)] bg-[var(--color-accent)] text-[var(--color-accent-fg)] px-4 py-2 text-sm font-medium hover:bg-[var(--color-accent-hover)] transition-colors"
                >
                    Sign in
                </Link>
            </div>
        );
    }

    const isAdmin = user.role === 'admin';

    return (
        <div>
            <h1 className="text-3xl font-semibold mb-6">Consignments</h1>

            {isLoading && (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            )}

            {data && data.data.length === 0 && (
                <EmptyState
                    title="No consignments"
                    description="Consignment requests from artists will appear here."
                />
            )}

            {data && data.data.length > 0 && (
                <div className="space-y-3">
                    {data.data.map((c) => (
                        <div
                            key={c.id}
                            className="rounded-[var(--radius-lg)] border border-[var(--color-border)] px-5 py-4 bg-[var(--color-bg)]"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    {c.artwork ? (
                                        <Link
                                            to={`/artworks/${c.artwork.slug}`}
                                            className="font-medium hover:underline block truncate"
                                        >
                                            {c.artwork.title}
                                        </Link>
                                    ) : (
                                        <p className="font-medium">Artwork #{c.artwork_id}</p>
                                    )}
                                    <div className="flex items-center gap-3 mt-1 text-xs text-[var(--color-text-muted)]">
                                        {c.artwork?.medium && <span>{c.artwork.medium}</span>}
                                        <span>Commission: {(c.commission_bps / 100).toFixed(0)}%</span>
                                    </div>
                                    {c.notes && (
                                        <p className="text-xs text-[var(--color-text-muted)] mt-1 line-clamp-2">
                                            {c.notes}
                                        </p>
                                    )}
                                </div>
                                <Badge variant={STATUS_VARIANT[c.status] ?? 'default'}>
                                    {STATUS_LABEL[c.status] ?? c.status}
                                </Badge>
                            </div>

                            {c.status === 'pending' && isAdmin && (
                                <div className="flex gap-2 mt-3">
                                    <Button
                                        size="sm"
                                        loading={approveMutation.isPending && approveMutation.variables === c.id}
                                        disabled={rejectMutation.isPending}
                                        onClick={() => approveMutation.mutate(c.id)}
                                    >
                                        Approve
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        loading={rejectMutation.isPending && rejectMutation.variables === c.id}
                                        disabled={approveMutation.isPending}
                                        onClick={() => rejectMutation.mutate(c.id)}
                                    >
                                        Reject
                                    </Button>
                                </div>
                            )}

                            {(approveMutation.isError || rejectMutation.isError) && (
                                <p className="text-xs text-red-600 mt-2">Action failed. Please try again.</p>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
