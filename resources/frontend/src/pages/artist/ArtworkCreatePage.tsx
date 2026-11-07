import { useState, type FormEvent } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Button, Input } from '@/components/ui';

type CreateArtworkPayload = {
    title: string;
    medium: string;
    dimensions: string;
    year_created: number | null;
    description: string;
    is_original: boolean;
    edition_number: number | null;
    edition_total: number | null;
};

const SELECT_CLASS =
    'w-full rounded-[var(--radius-sm)] border border-[var(--color-border)] px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]';

const TEXTAREA_CLASS =
    'w-full rounded-[var(--radius-sm)] border border-[var(--color-border)] px-3 py-2 text-sm bg-[var(--color-bg)] text-[var(--color-text)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]';

export default function ArtworkCreatePage() {
    const navigate = useNavigate();
    const [form, setForm] = useState<CreateArtworkPayload>({
        title: '',
        medium: '',
        dimensions: '',
        year_created: null,
        description: '',
        is_original: true,
        edition_number: null,
        edition_total: null,
    });

    const mutation = useMutation({
        mutationFn: async (data: CreateArtworkPayload) => {
            const res = await api.post<{ slug: string }>('/artworks', data);
            return res.data;
        },
        onSuccess: (artwork) => {
            navigate(`/artworks/${artwork.slug}`);
        },
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        mutation.mutate(form);
    };

    const set = <K extends keyof CreateArtworkPayload>(key: K, value: CreateArtworkPayload[K]) =>
        setForm((f) => ({ ...f, [key]: value }));

    return (
        <div className="max-w-2xl">
            <nav className="text-sm text-[var(--color-text-faint)] mb-6 flex items-center gap-1.5">
                <Link to="/dashboard" className="hover:text-[var(--color-text)] transition-colors">
                    Dashboard
                </Link>
                <span>/</span>
                <span className="text-[var(--color-text-muted)]">Add artwork</span>
            </nav>

            <h1 className="text-3xl font-semibold mb-8">Add artwork</h1>

            <form onSubmit={handleSubmit} className="space-y-5">
                <Input
                    label="Title"
                    type="text"
                    required
                    value={form.title}
                    onChange={(e) => set('title', e.target.value)}
                />

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-[var(--color-text)] mb-1">Medium</label>
                        <select
                            value={form.medium}
                            onChange={(e) => set('medium', e.target.value)}
                            className={SELECT_CLASS}
                        >
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
                        type="number"
                        min={1000}
                        max={2100}
                        value={form.year_created ?? ''}
                        onChange={(e) => set('year_created', e.target.value ? parseInt(e.target.value) : null)}
                    />
                </div>

                <Input
                    label="Dimensions"
                    type="text"
                    placeholder="e.g. 80 × 60 cm"
                    value={form.dimensions}
                    onChange={(e) => set('dimensions', e.target.value)}
                />

                <div>
                    <label className="block text-sm font-medium text-[var(--color-text)] mb-1">Description</label>
                    <textarea
                        rows={4}
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                        className={TEXTAREA_CLASS}
                    />
                </div>

                <label className="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        id="is_original"
                        checked={form.is_original}
                        onChange={(e) => set('is_original', e.target.checked)}
                        className="h-4 w-4 rounded border-[var(--color-border)] accent-[var(--color-accent)]"
                    />
                    <span className="text-sm text-[var(--color-text)]">Original work (not an edition)</span>
                </label>

                {!form.is_original && (
                    <div className="grid grid-cols-2 gap-4">
                        <Input
                            label="Edition number"
                            type="number"
                            min={1}
                            value={form.edition_number ?? ''}
                            onChange={(e) => set('edition_number', e.target.value ? parseInt(e.target.value) : null)}
                        />
                        <Input
                            label="Total in edition"
                            type="number"
                            min={1}
                            value={form.edition_total ?? ''}
                            onChange={(e) => set('edition_total', e.target.value ? parseInt(e.target.value) : null)}
                        />
                    </div>
                )}

                {mutation.isError && (
                    <p className="text-sm text-red-600">Failed to save artwork. Please try again.</p>
                )}

                <Button type="submit" loading={mutation.isPending}>
                    Save artwork
                </Button>
            </form>
        </div>
    );
}
