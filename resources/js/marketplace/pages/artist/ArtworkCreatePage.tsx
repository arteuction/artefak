import { useState, type FormEvent } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';

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
            const res = await api.post('/artworks', data);
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
            <nav className="text-sm text-gray-400 mb-6">
                <Link to="/dashboard" className="hover:text-black">Dashboard</Link>
                <span className="mx-2">/</span>
                <span className="text-gray-700">Add artwork</span>
            </nav>

            <h1 className="text-3xl font-semibold mb-8">Add artwork</h1>

            <form onSubmit={handleSubmit} className="space-y-5">
                <div>
                    <label className="block text-sm font-medium mb-1">Title *</label>
                    <input
                        type="text"
                        required
                        value={form.title}
                        onChange={(e) => set('title', e.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                    />
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium mb-1">Medium</label>
                        <select
                            value={form.medium}
                            onChange={(e) => set('medium', e.target.value)}
                            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
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
                    <div>
                        <label className="block text-sm font-medium mb-1">Year</label>
                        <input
                            type="number"
                            min={1000}
                            max={2100}
                            value={form.year_created ?? ''}
                            onChange={(e) => set('year_created', e.target.value ? parseInt(e.target.value) : null)}
                            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                        />
                    </div>
                </div>

                <div>
                    <label className="block text-sm font-medium mb-1">Dimensions</label>
                    <input
                        type="text"
                        placeholder="e.g. 80 × 60 cm"
                        value={form.dimensions}
                        onChange={(e) => set('dimensions', e.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                    />
                </div>

                <div>
                    <label className="block text-sm font-medium mb-1">Description</label>
                    <textarea
                        rows={4}
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                    />
                </div>

                <div className="flex items-center gap-3">
                    <input
                        type="checkbox"
                        id="is_original"
                        checked={form.is_original}
                        onChange={(e) => set('is_original', e.target.checked)}
                        className="rounded"
                    />
                    <label htmlFor="is_original" className="text-sm">Original work (not an edition)</label>
                </div>

                {!form.is_original && (
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">Edition number</label>
                            <input
                                type="number"
                                min={1}
                                value={form.edition_number ?? ''}
                                onChange={(e) => set('edition_number', e.target.value ? parseInt(e.target.value) : null)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Total in edition</label>
                            <input
                                type="number"
                                min={1}
                                value={form.edition_total ?? ''}
                                onChange={(e) => set('edition_total', e.target.value ? parseInt(e.target.value) : null)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-black"
                            />
                        </div>
                    </div>
                )}

                {mutation.isError && (
                    <p className="text-sm text-red-600">Failed to save artwork. Please try again.</p>
                )}

                <button
                    type="submit"
                    disabled={mutation.isPending}
                    className="rounded-md bg-black text-white px-6 py-2 text-sm font-medium disabled:opacity-40 hover:bg-gray-800"
                >
                    {mutation.isPending ? 'Saving…' : 'Save artwork'}
                </button>
            </form>
        </div>
    );
}
