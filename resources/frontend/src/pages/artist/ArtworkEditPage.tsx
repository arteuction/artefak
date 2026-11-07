import { useState, type FormEvent } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Artwork } from '@/lib/api';
import { Button, Input, Spinner, Badge } from '@/components/ui';
import ArtworkImageUpload from '@/components/ArtworkImageUpload';

const SELECT_CLASS =
    'w-full rounded-[var(--radius-sm)] border border-[var(--color-border)] px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]';

const TEXTAREA_CLASS =
    'w-full rounded-[var(--radius-sm)] border border-[var(--color-border)] px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]';

const STATUS_VARIANT: Record<string, 'default' | 'live' | 'sold' | 'draft' | 'auction'> = {
    draft:      'draft',
    listed:     'default',
    in_auction: 'auction',
    sold:       'sold',
    archived:   'draft',
};

const STATUS_LABEL: Record<string, string> = {
    draft:      'Draft',
    listed:     'Listed',
    in_auction: 'In Auction',
    sold:       'Sold',
    archived:   'Archived',
};

type EditPayload = Pick<Artwork, 'title' | 'medium' | 'dimensions' | 'year_created' | 'description' | 'is_original' | 'edition_number' | 'edition_total'>;

export default function ArtworkEditPage() {
    const { slug } = useParams<{ slug: string }>();
    const queryClient = useQueryClient();
    const [uploadDone, setUploadDone] = useState(false);
    const [submitRequested, setSubmitRequested] = useState(false);

    const { data: artwork, isLoading } = useQuery({
        queryKey: ['artwork-edit', slug],
        queryFn: async () => {
            const res = await api.get<Artwork>(`/artworks/${slug}`);
            return res.data;
        },
        enabled: !!slug,
    });

    const saveMutation = useMutation({
        mutationFn: async (data: Partial<EditPayload>) => {
            const res = await api.patch<Artwork>(`/artworks/${slug}`, data);
            return res.data;
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['artwork-edit', slug] });
            void queryClient.invalidateQueries({ queryKey: ['my-artworks'] });
        },
    });

    const submitMutation = useMutation({
        mutationFn: async () => {
            const res = await api.post<Artwork>(`/artworks/${slug}/submit`);
            return res.data;
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['artwork-edit', slug] });
            void queryClient.invalidateQueries({ queryKey: ['my-artworks'] });
            setSubmitRequested(false);
        },
    });

    if (isLoading) {
        return (
            <div className="flex justify-center py-24">
                <Spinner size="lg" />
            </div>
        );
    }

    if (!artwork) {
        return <p className="text-red-600">Artwork not found.</p>;
    }

    const canSubmit = artwork.status === 'draft';

    const handleSave = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        const isOriginal = fd.get('is_original') === 'on';
        saveMutation.mutate({
            title:          String(fd.get('title') ?? ''),
            medium:         String(fd.get('medium') ?? ''),
            dimensions:     String(fd.get('dimensions') ?? ''),
            year_created:   fd.get('year_created') ? parseInt(String(fd.get('year_created'))) : null,
            description:    String(fd.get('description') ?? ''),
            is_original:    isOriginal,
            edition_number: !isOriginal && fd.get('edition_number') ? parseInt(String(fd.get('edition_number'))) : null,
            edition_total:  !isOriginal && fd.get('edition_total') ? parseInt(String(fd.get('edition_total'))) : null,
        });
    };

    return (
        <div className="max-w-2xl">
            <nav className="text-sm text-[var(--color-text-faint)] mb-6 flex items-center gap-1.5">
                <Link to="/dashboard" className="hover:text-[var(--color-text)] transition-colors">
                    Dashboard
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)] truncate">{artwork.title}</span>
            </nav>

            <div className="flex items-start justify-between mb-6 gap-4">
                <h1 className="text-3xl font-semibold">{artwork.title}</h1>
                <Badge variant={STATUS_VARIANT[artwork.status] ?? 'default'}>
                    {STATUS_LABEL[artwork.status] ?? artwork.status}
                </Badge>
            </div>

            {/* Image upload — only for draft artworks */}
            {canSubmit && (
                <section className="mb-8">
                    <h2 className="text-base font-medium mb-3">Artwork image</h2>
                    {uploadDone ? (
                        <div className="rounded-[var(--radius-lg)] border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                            Image uploaded successfully. It will be processed for display shortly.
                        </div>
                    ) : (
                        <ArtworkImageUpload
                            artworkId={artwork.id}
                            onComplete={() => setUploadDone(true)}
                        />
                    )}
                </section>
            )}

            {/* Edit form */}
            <form onSubmit={handleSave} className="space-y-5">
                <Input
                    label="Title"
                    name="title"
                    type="text"
                    required
                    defaultValue={artwork.title}
                />

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-[var(--color-text)] mb-1">Medium</label>
                        <select name="medium" defaultValue={artwork.medium ?? ''} className={SELECT_CLASS}>
                            <option value="">Select…</option>
                            <option value="painting">Painting</option>
                            <option value="sculpture">Sculpture</option>
                            <option value="photography">Photography</option>
                            <option value="digital">Digital</option>
                            <option value="nft">NFT</option>
                            <option value="mixed">Mixed</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <Input
                        label="Year"
                        name="year_created"
                        type="number"
                        min={1000}
                        max={2100}
                        defaultValue={artwork.year_created ?? ''}
                    />
                </div>

                <Input
                    label="Dimensions"
                    name="dimensions"
                    type="text"
                    placeholder="e.g. 80 × 60 cm"
                    defaultValue={artwork.dimensions ?? ''}
                />

                <div>
                    <label className="block text-sm font-medium text-[var(--color-text)] mb-1">Description</label>
                    <textarea
                        name="description"
                        rows={4}
                        defaultValue={artwork.description ?? ''}
                        className={TEXTAREA_CLASS}
                    />
                </div>

                <label className="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        name="is_original"
                        defaultChecked={artwork.is_original}
                        className="h-4 w-4 rounded border-[var(--color-border)] accent-[var(--color-accent)]"
                    />
                    <span className="text-sm text-[var(--color-text)]">Original work (not an edition)</span>
                </label>

                {saveMutation.isError && (
                    <p className="text-sm text-red-600">Failed to save. Please try again.</p>
                )}
                {saveMutation.isSuccess && (
                    <p className="text-sm text-green-700">Saved.</p>
                )}

                <div className="flex gap-3 flex-wrap">
                    <Button type="submit" variant="secondary" loading={saveMutation.isPending}>
                        Save changes
                    </Button>

                    {canSubmit && !submitRequested && (
                        <Button
                            type="button"
                            onClick={() => setSubmitRequested(true)}
                        >
                            Submit for review
                        </Button>
                    )}
                </div>
            </form>

            {/* Submission confirmation */}
            {submitRequested && (
                <div className="mt-6 rounded-[var(--radius-lg)] border border-[var(--color-border)] bg-[var(--color-bg-subtle)] p-5">
                    <p className="font-medium mb-1">Submit this artwork for review?</p>
                    <p className="text-sm text-[var(--color-text-muted)] mb-4">
                        Once submitted, you won&apos;t be able to edit the artwork until it has been reviewed.
                    </p>
                    <div className="flex gap-3">
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => setSubmitRequested(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            loading={submitMutation.isPending}
                            onClick={() => submitMutation.mutate()}
                        >
                            Confirm submission
                        </Button>
                    </div>
                    {submitMutation.isError && (
                        <p className="text-xs text-red-600 mt-2">Submission failed. Please try again.</p>
                    )}
                </div>
            )}

            {!canSubmit && artwork.status !== 'archived' && (
                <p className="mt-6 text-sm text-[var(--color-text-muted)]">
                    This artwork is{' '}
                    <span className="font-medium">{STATUS_LABEL[artwork.status] ?? artwork.status}</span>
                    {' '}and cannot be edited.{' '}
                    <Link to={`/artworks/${artwork.slug}`} className="underline hover:no-underline">
                        View public page →
                    </Link>
                </p>
            )}
        </div>
    );
}
